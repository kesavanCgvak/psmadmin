<?php

namespace App\Jobs;

use App\Models\Equipment;
use App\Models\FlexIntegrationLog;
use App\Models\FlexSalesQuoteSyncLog;
use App\Models\RentalJob;
use App\Models\RentalRequestProviderQuote;
use App\Models\SupplyJob;
use App\Services\FlexIntegrationLogger;
use App\Services\FlexIntegrationService;
use App\Support\FlexIntegrationDebugLog;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Runs synchronously when dispatched (does not implement ShouldQueue).
 * Flex quote creation is not queued.
 */
class CreateFlexQuoteFromRentalRequestJob
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $rentalJobId) {}

    public function handle(): void
    {
        $rentalJob = RentalJob::query()
            ->with([
                'user.profile',
                'supplyJobs.provider',
                'supplyJobs.products.product.brand',
            ])
            ->find($this->rentalJobId);

        if (!$rentalJob || !$rentalJob->user) {
            FlexIntegrationDebugLog::warning($this->rentalJobId, null, 'FLEX_QUOTE_JOB', 'ABORTED', [
                'reason' => 'rental_job_or_requester_missing',
            ]);

            return;
        }

        FlexIntegrationDebugLog::info($rentalJob->id, null, 'FLEX_QUOTE_JOB', 'STARTED', [
            'supply_jobs_count' => $rentalJob->supplyJobs->count(),
            'execution' => 'synchronous',
        ]);

        foreach ($rentalJob->supplyJobs as $supplyJob) {
            FlexIntegrationDebugLog::info($rentalJob->id, (int) $supplyJob->provider_id, 'PROVIDER_PROCESSING', 'STARTED', [
                'supply_job_id' => $supplyJob->id,
            ]);

            $this->processSupplyJob($rentalJob, $supplyJob);

            FlexIntegrationDebugLog::debug($rentalJob->id, (int) $supplyJob->provider_id, 'PROVIDER_PROCESSING', 'FINISHED', [
                'supply_job_id' => $supplyJob->id,
            ]);
        }

        $this->refreshRentalJobFlexSummary($rentalJob->id);

        FlexIntegrationDebugLog::info($rentalJob->id, null, 'FLEX_QUOTE_JOB', 'ALL_PROVIDERS_DONE', []);
    }

    protected function processSupplyJob(RentalJob $rentalJob, SupplyJob $supplyJob): void
    {
        $steps = [];
        $appendStep = function (string $message) use (&$steps) {
            $steps[] = $message;
        };

        $providerId = (int) $supplyJob->provider_id;
        $ilog = new FlexIntegrationLogger($rentalJob->id, $providerId);

        $appendStep('Flex integration started for provider supply job');

        $ilog->log(
            FlexIntegrationLog::ACTION_CHECK_INTEGRATION,
            FlexIntegrationLog::STATUS_PROCESSING,
            null,
            ['supply_job_id' => $supplyJob->id],
            null,
        );

        $syncLog = FlexSalesQuoteSyncLog::query()->create([
            'rental_job_id' => $rentalJob->id,
            'supply_job_id' => $supplyJob->id,
            'provider_company_id' => $providerId,
            'status' => FlexIntegrationService::SYNC_PENDING,
            'steps' => $steps,
        ]);

        if (!FlexIntegrationService::checkCompanyIntegration($providerId)) {
            FlexIntegrationDebugLog::info($rentalJob->id, $providerId, 'CHECK_INTEGRATION', 'SKIPPED', [
                'reason' => 'not_flex_provider_or_no_integration_row',
            ]);
            $appendStep('Skipped: provider has no Flex integration');
            $ilog->log(
                FlexIntegrationLog::ACTION_CHECK_INTEGRATION,
                FlexIntegrationLog::STATUS_SKIPPED,
                null,
                ['reason' => 'rental_software_or_integration'],
                null,
            );
            $syncLog->update(['status' => FlexIntegrationService::SYNC_COMPLETED, 'steps' => $steps]);

            return;
        }

        $ilog->log(
            FlexIntegrationLog::ACTION_CHECK_INTEGRATION,
            FlexIntegrationLog::STATUS_SUCCESS,
            null,
            ['supply_job_id' => $supplyJob->id],
            null,
        );

        if ($supplyJob->flex_sales_quote_id) {
            FlexIntegrationDebugLog::info($rentalJob->id, $providerId, 'CREATE_QUOTE', 'SKIPPED', [
                'reason' => 'flex_sales_quote_id_already_set',
                'existing_flex_quote_id' => $supplyJob->flex_sales_quote_id,
            ]);
            $appendStep('Skipped: flex_sales_quote_id already set (duplicate prevention)');
            $ilog->log(
                FlexIntegrationLog::ACTION_CREATE_QUOTE,
                FlexIntegrationLog::STATUS_SKIPPED,
                null,
                ['existing_flex_quote_id' => $supplyJob->flex_sales_quote_id],
                null,
                null,
                $supplyJob->flex_sales_quote_id,
            );
            $syncLog->update([
                'status' => FlexIntegrationService::SYNC_COMPLETED,
                'flex_sales_quote_id' => $supplyJob->flex_sales_quote_id,
                'flex_sales_quote_number' => $supplyJob->flex_sales_quote_number,
                'steps' => $steps,
            ]);

            return;
        }

        $service = FlexIntegrationService::forProviderCompany($providerId);
        if (!$service) {
            FlexIntegrationDebugLog::info($rentalJob->id, $providerId, 'CHECK_INTEGRATION', 'SKIPPED', [
                'reason' => 'incomplete_flex_api_credentials',
            ]);
            $appendStep('Skipped: Flex credentials incomplete');
            $ilog->log(
                FlexIntegrationLog::ACTION_CHECK_INTEGRATION,
                FlexIntegrationLog::STATUS_SKIPPED,
                null,
                ['reason' => 'incomplete_credentials'],
                null,
            );
            $syncLog->update(['status' => FlexIntegrationService::SYNC_COMPLETED, 'steps' => $steps]);

            return;
        }

        $service->setFlexLogger($ilog);

        $service->logPreFlightDiagnostics($rentalJob->id);

        $supplyJob->flex_sync_status = FlexIntegrationService::SYNC_PROCESSING;
        $supplyJob->save();

        $attached = [];
        $missing = [];
        $missingForEmail = [];
        $flexClientId = null;
        $quoteId = null;
        $quoteNumber = null;

        try {
            FlexIntegrationDebugLog::debug($rentalJob->id, $providerId, 'CREATE_CLIENT', 'STARTED', []);

            $flexClientId = $service->getOrCreateClient($rentalJob->user);
            $appendStep('Client resolved in Flex');

            FlexIntegrationDebugLog::info($rentalJob->id, $providerId, 'CREATE_CLIENT', 'SUCCESS', [
                'flex_client_id' => $flexClientId,
            ]);

            FlexIntegrationDebugLog::debug($rentalJob->id, $providerId, 'CREATE_QUOTE', 'STARTED', []);

            $quote = $service->createSalesQuote($rentalJob, $flexClientId);
            $quoteId = $quote['id'];
            $quoteNumber = $quote['number'];
            $appendStep('Sales quote created in Flex');

            FlexIntegrationDebugLog::info($rentalJob->id, $providerId, 'CREATE_QUOTE', 'SUCCESS', [
                'flex_quote_id' => $quoteId,
                'flex_quote_number' => $quoteNumber,
            ]);

            foreach ($supplyJob->products as $line) {
                $product = $line->product;
                if (!$product) {
                    continue;
                }

                $qty = (int) ($line->required_quantity ?? $line->offered_quantity ?? 0);
                if ($qty < 1) {
                    continue;
                }

                $displayName = FlexIntegrationService::productDisplayName($product);

                $inventory = Equipment::query()
                    ->where('company_id', $providerId)
                    ->where('product_id', $product->id)
                    ->first();

                $flexResourceId = $inventory?->flex_resource_id;
                if ($flexResourceId) {
                    FlexIntegrationDebugLog::debug($rentalJob->id, $providerId, 'PRODUCT_RESOLVED', 'FROM_INVENTORY', [
                        'product_id' => $product->id,
                        'flex_resource_id' => $flexResourceId,
                    ]);
                }

                if (!$flexResourceId) {
                    $flexResourceId = $service->searchFlexProduct($displayName);
                    if ($flexResourceId) {
                        FlexIntegrationService::persistFlexResourceOnInventory($providerId, (int) $product->id, $flexResourceId);
                    }
                }

                if (!$flexResourceId) {
                    $missing[] = $displayName;
                    $missingForEmail[] = ['name' => $displayName, 'quantity' => $qty];
                    $ilog->log(
                        FlexIntegrationLog::ACTION_PRODUCT_NOT_FOUND,
                        FlexIntegrationLog::STATUS_FAILED,
                        null,
                        ['product_id' => $product->id, 'name' => $displayName, 'quantity' => $qty],
                        null,
                        'Not found in Flex inventory search',
                    );
                    FlexIntegrationDebugLog::warning($rentalJob->id, $providerId, 'PRODUCT_NOT_FOUND', 'FAILED', [
                        'product_id' => $product->id,
                        'name' => $displayName,
                        'quantity' => $qty,
                    ]);
                    $appendStep('Product missing in Flex: ' . $displayName);

                    continue;
                }

                try {
                    FlexIntegrationDebugLog::debug($rentalJob->id, $providerId, 'ADD_PRODUCT_TO_QUOTE', 'STARTED', [
                        'product_id' => $product->id,
                        'flex_resource_id' => $flexResourceId,
                        'quantity' => $qty,
                    ]);

                    $attachResult = $service->attachProductToSalesQuote($quoteId, $flexResourceId, $qty);
                    $service->trackFinDocQuickLineAdded();

                    FlexIntegrationDebugLog::info($rentalJob->id, $providerId, 'ADD_PRODUCT_TO_QUOTE', 'SUCCESS', [
                        'product_id' => $product->id,
                        'flex_line_item_id' => $attachResult['flex_product_id'],
                    ]);

                    $attached[] = [
                        'product_id' => $product->id,
                        'name' => $displayName,
                        'quantity' => $qty,
                        'flex_resource_id' => $flexResourceId,
                        'flex_line_item_id' => $attachResult['flex_product_id'],
                    ];
                } catch (\Throwable $e) {
                    FlexIntegrationDebugLog::error($rentalJob->id, $providerId, 'ADD_PRODUCT_TO_QUOTE', 'FAILED', [
                        'product_id' => $product->id,
                        'error' => $e->getMessage(),
                    ]);
                    $missing[] = $displayName . ' (attach failed)';
                    $missingForEmail[] = ['name' => $displayName . ' (attach failed)', 'quantity' => $qty];
                    $appendStep('Flex error attaching product: ' . $e->getMessage());
                }
            }

            if ($missingForEmail !== []) {
                $supplyJob->provider?->loadMissing('getDefaultcontact');
                if ($supplyJob->provider) {
                    $service->sendMissingProductsEmail($supplyJob->provider, $rentalJob, $missingForEmail);
                }
            }

            if ($missing === []) {
                $syncStatus = FlexIntegrationService::SYNC_COMPLETED;
            } elseif ($attached === []) {
                $syncStatus = FlexIntegrationService::SYNC_FAILED;
            } else {
                $syncStatus = FlexIntegrationService::SYNC_PARTIAL;
            }

            $supplyJob->flex_sales_quote_id = $quoteId;
            $supplyJob->flex_sales_quote_number = $quoteNumber;
            $supplyJob->flex_sync_status = $syncStatus;
            $supplyJob->save();

            RentalRequestProviderQuote::query()->updateOrCreate(
                [
                    'rental_request_id' => $rentalJob->id,
                    'provider_id' => $providerId,
                ],
                [
                    'supply_job_id' => $supplyJob->id,
                    'flex_quote_id' => $quoteId,
                    'flex_quote_number' => $quoteNumber,
                    'status' => $syncStatus,
                ],
            );

            $syncLog->update([
                'status' => $syncStatus,
                'flex_client_id' => $flexClientId,
                'flex_sales_quote_id' => $quoteId,
                'flex_sales_quote_number' => $quoteNumber,
                'products_attached' => $attached,
                'products_missing' => $missing,
                'steps' => $steps,
            ]);
        } catch (\Throwable $e) {
            FlexIntegrationDebugLog::error($rentalJob->id, $providerId, 'FLEX_QUOTE_FLOW', 'FAILED', [
                'supply_job_id' => $supplyJob->id,
                'error' => $e->getMessage(),
            ]);

            $appendStep('Flex error: ' . $e->getMessage());

            $ilog->log(
                FlexIntegrationLog::ACTION_API_ERROR,
                FlexIntegrationLog::STATUS_FAILED,
                null,
                null,
                null,
                $e->getMessage(),
                $quoteId,
            );

            $supplyJob->flex_sync_status = FlexIntegrationService::SYNC_FAILED;
            $supplyJob->save();

            RentalRequestProviderQuote::query()->updateOrCreate(
                [
                    'rental_request_id' => $rentalJob->id,
                    'provider_id' => $providerId,
                ],
                [
                    'supply_job_id' => $supplyJob->id,
                    'flex_quote_id' => $quoteId,
                    'flex_quote_number' => $quoteNumber,
                    'status' => FlexIntegrationService::SYNC_FAILED,
                ],
            );

            $syncLog->update([
                'status' => FlexIntegrationService::SYNC_FAILED,
                'flex_client_id' => $flexClientId,
                'flex_sales_quote_id' => $quoteId,
                'flex_sales_quote_number' => $quoteNumber,
                'products_attached' => $attached,
                'products_missing' => $missing,
                'error_message' => $e->getMessage(),
                'steps' => $steps,
            ]);
        }
    }

    protected function refreshRentalJobFlexSummary(int $rentalJobId): void
    {
        $supplyJobs = SupplyJob::query()
            ->where('rental_job_id', $rentalJobId)
            ->get();

        $withStatus = $supplyJobs->filter(fn (SupplyJob $sj) => $sj->flex_sync_status !== null && $sj->flex_sync_status !== '');
        if ($withStatus->isEmpty()) {
            return;
        }

        $values = $withStatus->pluck('flex_sync_status')->unique()->values()->all();

        $overall = FlexIntegrationService::SYNC_COMPLETED;
        if (in_array(FlexIntegrationService::SYNC_FAILED, $values, true)) {
            if (in_array(FlexIntegrationService::SYNC_COMPLETED, $values, true) || in_array(FlexIntegrationService::SYNC_PARTIAL, $values, true)) {
                $overall = FlexIntegrationService::SYNC_PARTIAL;
            } else {
                $overall = FlexIntegrationService::SYNC_FAILED;
            }
        } elseif (in_array(FlexIntegrationService::SYNC_PARTIAL, $values, true)) {
            $overall = FlexIntegrationService::SYNC_PARTIAL;
        }

        $quoted = $supplyJobs->filter(fn (SupplyJob $sj) => !empty($sj->flex_sales_quote_id));
        $rentalJob = RentalJob::query()->find($rentalJobId);
        if (!$rentalJob) {
            return;
        }

        if ($quoted->count() === 1) {
            $first = $quoted->first();
            $rentalJob->flex_sales_quote_id = $first->flex_sales_quote_id;
            $rentalJob->flex_sales_quote_number = $first->flex_sales_quote_number;
        } else {
            $rentalJob->flex_sales_quote_id = null;
            $rentalJob->flex_sales_quote_number = null;
        }

        $rentalJob->flex_sync_status = $overall;
        $rentalJob->save();
    }
}
