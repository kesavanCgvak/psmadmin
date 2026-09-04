<?php

namespace App\Services;

use App\Helpers\EmailHelper;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\RentalJob;
use App\Models\SupplyJob;
use App\Support\HireTrackIntegrationDebugLog;
use App\Support\RentalShippingMethods;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class HireTrackIntegrationService
{
    public static function isHireTrackSoftware(?string $name): bool
    {
        $softwareName = strtolower(trim((string) $name));

        return $softwareName !== '' && str_contains($softwareName, 'hiretrack');
    }

    public static function checkCompanyIntegration(int $providerCompanyId): bool
    {
        $company = Company::with('rentalSoftware')->find($providerCompanyId);
        if (!$company) {
            Log::info('HireTrack integration: provider company not found.', [
                'provider_company_id' => $providerCompanyId,
            ]);

            return false;
        }

        $softwareName = $company->rentalSoftware->name ?? null;
        if (!self::isHireTrackSoftware($softwareName)) {
            Log::info('Provider does not use HireTrack rental software. Skipping HireTrack import email.', [
                'provider_company_id' => $providerCompanyId,
                'rental_software' => $softwareName,
            ]);

            return false;
        }

        return true;
    }

    public static function hasHireTrackCode(?string $softwareCode): bool
    {
        return trim((string) $softwareCode) !== '';
    }

    public static function productDisplayName(Product $product): string
    {
        $product->loadMissing('brand');
        $brand = $product->brand->name ?? '';

        return trim($brand . ' ' . ($product->model ?? ''));
    }

    /**
     * Format a rental-request date using the provider's date_formats row (via companies.date_format_id).
     * Falls back to the existing application date format when none is configured.
     */
    public static function formatDateForProvider(mixed $date, ?Company $provider): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        $carbon = $date instanceof CarbonInterface
            ? $date
            : Carbon::parse((string) $date);

        $dateFormatId = $provider?->date_format_id;

        return $carbon->format(
            SupplierSmsNotifier::getPhpDateFormat($dateFormatId !== null ? (int) $dateFormatId : null)
        );
    }

    /**
     * Split supply-job lines into HireTrack import rows (software code + quantity) and skipped products.
     *
     * @param  iterable<mixed>  $lines
     * @param  Collection<int|string, Equipment>  $equipmentByProductId
     * @return array{included: array<int, array{type: string, quantity: int}>, skipped: array<int, array{name: string, quantity: int}>}
     */
    public function partitionLines(iterable $lines, Collection $equipmentByProductId): array
    {
        $included = [];
        $skipped = [];

        foreach ($lines as $line) {
            $product = $line->product ?? null;
            if (!$product) {
                continue;
            }

            $qty = (int) ($line->required_quantity ?? $line->offered_quantity ?? 0);
            if ($qty < 1) {
                continue;
            }

            $productId = (int) $product->id;
            $softwareCode = trim((string) ($equipmentByProductId->get($productId)?->software_code ?? ''));
            $displayName = self::productDisplayName($product);

            if (!self::hasHireTrackCode($softwareCode)) {
                $skipped[] = [
                    'name' => $displayName !== '' ? $displayName : ($product->model ?? 'Unknown product'),
                    'quantity' => $qty,
                ];

                continue;
            }

            $included[] = [
                'type' => $softwareCode,
                'quantity' => $qty,
            ];
        }

        return [
            'included' => $included,
            'skipped' => $skipped,
        ];
    }

    /**
     * Build a HireTrack import .txt file: one `softwareCode,quantity` line per product, no header.
     *
     * @param  array<int, array{type: string, quantity: int}>  $includedRows
     */
    public static function buildTxt(array $includedRows): string
    {
        $lines = [];
        foreach ($includedRows as $row) {
            $lines[] = (string) $row['type'] . ',' . (int) $row['quantity'];
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * @param  array<int, array{name: string, quantity: int}>  $skipped
     */
    public static function skippedProductsSectionHtml(array $skipped): string
    {
        if ($skipped === []) {
            return '';
        }

        $listHtml = '<ul>';
        foreach ($skipped as $item) {
            $name = e($item['name'] ?? '');
            $qty = (int) ($item['quantity'] ?? 0);
            $listHtml .= '<li>' . $name . ' — Quantity: ' . $qty . '</li>';
        }
        $listHtml .= '</ul>';

        return '<p>The following requested products could not be included in the text file because they do not have a HireTrack rental software code:</p>'
            . $listHtml
            . '<p>Please review these items manually as part of the rental request.</p>';
    }

    public function processSupplyJob(RentalJob $rentalJob, SupplyJob $supplyJob): void
    {
        $providerId = (int) $supplyJob->provider_id;
        $provider = $supplyJob->provider;

        HireTrackIntegrationDebugLog::step(
            $rentalJob->id,
            $providerId,
            'PROCESS_PRODUCTS',
            'STARTED',
            ['supply_job_id' => $supplyJob->id, 'product_count' => $supplyJob->products->count()],
            'Classify products by HireTrack software_code, then generate import txt and email provider',
        );

        $productIds = $supplyJob->products->pluck('product_id')->filter()->unique()->values()->all();
        $equipmentByProductId = Equipment::query()
            ->where('company_id', $providerId)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $partition = $this->partitionLines($supplyJob->products, $equipmentByProductId);
        $included = $partition['included'];
        $skipped = $partition['skipped'];

        HireTrackIntegrationDebugLog::step(
            $rentalJob->id,
            $providerId,
            'PROCESS_PRODUCTS',
            'SUCCESS',
            [
                'included_count' => count($included),
                'skipped_count' => count($skipped),
            ],
            count($included) > 0 ? 'Generate HireTrack import txt and email provider' : 'Skip empty txt; email provider with skipped products',
        );

        $txtContent = null;
        $txtFilename = null;
        if ($included !== []) {
            $txtContent = self::buildTxt($included);
            $txtFilename = 'HireTrackImportPSM.txt';

            HireTrackIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'GENERATE_TXT',
                'SUCCESS',
                ['filename' => $txtFilename, 'row_count' => count($included)],
                'Email txt to provider default contact',
            );
        } else {
            HireTrackIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'GENERATE_TXT',
                'SKIPPED',
                ['reason' => 'no_products_with_hiretrack_code'],
                'Email provider with rental request details and skipped products',
            );
        }

        if (!$provider) {
            HireTrackIntegrationDebugLog::warning($rentalJob->id, $providerId, 'SEND_EMAIL', 'SKIPPED', [
                'reason' => 'provider_missing',
            ]);

            return;
        }

        $this->sendProviderEmail($provider, $rentalJob, $supplyJob, $skipped, $txtContent, $txtFilename);
    }

    /**
     * @param  array<int, array{name: string, quantity: int}>  $skipped
     */
    public function sendProviderEmail(
        Company $provider,
        RentalJob $rentalJob,
        SupplyJob $supplyJob,
        array $skipped,
        ?string $txtContent,
        ?string $txtFilename,
    ): void {
        $provider->loadMissing('getDefaultcontact');
        $defaultContact = $provider->getDefaultcontact;
        $to = $defaultContact->email ?? null;
        if (!$to) {
            Log::warning('HireTrack integration: cannot email provider (no default contact email).', [
                'provider_company_id' => $provider->id,
                'rental_job_id' => $rentalJob->id,
            ]);
            HireTrackIntegrationDebugLog::warning($rentalJob->id, $provider->id, 'SEND_EMAIL', 'SKIPPED', [
                'reason' => 'no_default_contact_email',
            ]);

            return;
        }

        $rentalJob->loadMissing(['user.profile', 'user.company']);
        $provider->loadMissing('dateFormat');
        $supplyJob->loadMissing('comments');
        $user = $rentalJob->user;
        $providerContactName = $defaultContact->full_name ?? $defaultContact->email ?? 'there';
        $fromDate = self::formatDateForProvider($rentalJob->from_date, $provider);
        $toDate = self::formatDateForProvider($rentalJob->to_date, $provider);
        $deliveryAddress = $rentalJob->delivery_address !== null && $rentalJob->delivery_address !== ''
            ? $rentalJob->delivery_address
            : 'N/A';

        $csvNote = $txtContent !== null && $txtContent !== ''
            ? '<p>Please find the requested equipment with HireTrack codes attached as a text file.</p>'
            : '<p>None of the requested products have a HireTrack rental software code, so no text file is attached.</p>';

        $globalMessageSection = '';
        if (!empty($rentalJob->global_message)) {
            $globalMessageSection = '<h3 style="color: #1a73e8;">Global Message</h3><p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">' . e($rentalJob->global_message) . '</p>';
        }

        $offerRequirementsSection = '';
        if (!empty($rentalJob->offer_requirements)) {
            $offerRequirementsSection = '<h3 style="color: #1a73e8;">Offer Requirements</h3><p>' . e($rentalJob->offer_requirements) . '</p>';
        }

        $privateMessage = $supplyJob->comments
            ->first(fn ($comment) => !empty($comment->is_private) && !empty($comment->message))
            ?->message;
        $privateMessageSection = '';
        if (!empty($privateMessage)) {
            $privateMessageSection = '<h3 style="color: #1a73e8;">Private Message</h3><p style="background: #f9f9f9; padding: 12px; border-left: 4px solid #1a73e8;">' . e($privateMessage) . '</p>';
        }

        $mailContent = [
            'provider_contact_name' => $providerContactName,
            'rental_name' => $rentalJob->name,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'shipping_method' => RentalShippingMethods::label($rentalJob->shipping_method),
            'delivery_address' => $deliveryAddress,
            'user_name' => $user->profile->full_name ?? $user->name ?? 'Unknown User',
            'user_company' => $user->company->name ?? 'N/A',
            'csv_note' => $csvNote,
            'skipped_products_section' => self::skippedProductsSectionHtml($skipped),
            'global_message_section' => $globalMessageSection,
            'offer_requirements_section' => $offerRequirementsSection,
            'private_message_section' => $privateMessageSection,
            'current_year' => (string) date('Y'),
        ];

        HireTrackIntegrationDebugLog::step(
            $rentalJob->id,
            $provider->id,
            'SEND_EMAIL',
            'STARTED',
            [
                'to' => $to,
                'has_txt_attachment' => $txtContent !== null && $txtContent !== '',
                'skipped_count' => count($skipped),
            ],
            'Send HireTrack rental request email via EmailHelper',
        );

        $sent = EmailHelper::send('hireTrackRentalRequest', $mailContent, function ($message) use ($to, $providerContactName, $txtContent, $txtFilename) {
            $message->to($to, $providerContactName)
                ->from(config('mail.from.address'), config('mail.from.name'));

            if ($txtContent !== null && $txtContent !== '' && $txtFilename) {
                $message->attachData($txtContent, $txtFilename, [
                    'mime' => 'text/plain',
                ]);
            }
        });

        if ($sent) {
            HireTrackIntegrationDebugLog::step(
                $rentalJob->id,
                $provider->id,
                'SEND_EMAIL',
                'SUCCESS',
                ['to' => $to],
                'Process next HireTrack supply job',
            );
            Log::info('HireTrack integration: rental request email sent to provider', [
                'provider_company_id' => $provider->id,
                'rental_job_id' => $rentalJob->id,
                'has_txt_attachment' => $txtContent !== null && $txtContent !== '',
            ]);

            return;
        }

        HireTrackIntegrationDebugLog::error($rentalJob->id, $provider->id, 'SEND_EMAIL', 'FAILED', [
            'to' => $to,
        ]);
        Log::error('HireTrack integration: failed to send rental request email', [
            'provider_company_id' => $provider->id,
            'rental_job_id' => $rentalJob->id,
        ]);
    }
}
