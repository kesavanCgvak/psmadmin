<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Equipment;
use App\Models\RentalJob;
use App\Models\RentmanEquipment;
use App\Models\RentmanIntegrationLog;
use App\Models\SupplyJob;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\RentmanIntegrationDebugLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Rentman Project Request push for rental requests (mirrors FlexIntegrationService).
 */
class RentmanIntegrationService
{
    public const SYNC_PENDING = 'PENDING';

    public const SYNC_PROCESSING = 'PROCESSING';

    public const SYNC_COMPLETED = 'COMPLETED';

    public const SYNC_FAILED = 'FAILED';

    public const SYNC_PARTIAL = 'PARTIAL';

    protected int $providerCompanyId;

    protected CompanyIntegration $integration;

    protected string $baseUrl;

    protected string $authToken;

    protected int $timeout;

    protected ?RentmanIntegrationLogger $rentmanLogger = null;

    protected ?int $rentalRequestId = null;

    protected function __construct(
        int $providerCompanyId,
        CompanyIntegration $integration,
        string $baseUrl,
        string $authToken,
    ) {
        $this->providerCompanyId = $providerCompanyId;
        $this->integration = $integration;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authToken = $authToken;
        $this->timeout = (int) config('rentman.timeout', 120);
    }

    public function setRentmanLogger(?RentmanIntegrationLogger $logger): self
    {
        $this->rentmanLogger = $logger;

        return $this;
    }

    public function setRentalRequestId(?int $rentalRequestId): self
    {
        $this->rentalRequestId = $rentalRequestId;

        return $this;
    }

    public function getBaseUrlForLogging(): string
    {
        return $this->baseUrl;
    }

    public static function forProviderCompany(int $providerCompanyId): ?self
    {
        $integration = CompanyIntegration::where('company_id', $providerCompanyId)
            ->where('integration_type', 'rentman')
            ->first();

        if (!$integration || !$integration->isConnected()) {
            return null;
        }

        $baseUrl = rtrim((string) ($integration->api_base_url ?: config('services.rentman.base_url', '')), '/');
        $apiKey = (string) $integration->api_key;

        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        return new self($providerCompanyId, $integration, $baseUrl, $apiKey);
    }

    public static function checkCompanyIntegration(int $providerCompanyId): bool
    {
        $company = Company::with('rentalSoftware')->find($providerCompanyId);
        if (!$company) {
            Log::info('Rentman integration: provider company not found.', ['provider_company_id' => $providerCompanyId]);

            return false;
        }

        $softwareName = strtolower(trim((string) ($company->rentalSoftware->name ?? '')));
        if ($softwareName === '' || !str_contains($softwareName, 'rentman')) {
            Log::info('Provider does not have Rentman integration. Skipping Project Request creation.', [
                'provider_company_id' => $providerCompanyId,
                'rental_software' => $company->rentalSoftware->name ?? null,
            ]);

            return false;
        }

        if (!CompanyIntegration::where('company_id', $providerCompanyId)
            ->where('integration_type', 'rentman')
            ->exists()) {
            Log::info('Provider does not have Rentman integration. Skipping Project Request creation.', [
                'provider_company_id' => $providerCompanyId,
                'reason' => 'no_rentman_company_integration',
            ]);

            return false;
        }

        return true;
    }

    public function logPreFlightDiagnostics(int $rentalRequestId): void
    {
        $url = $this->baseUrl . '/equipment';
        $params = ['limit' => 1];

        try {
            $response = $this->rentmanHttp(
                'GET',
                $url,
                $params,
                RentmanIntegrationLog::ACTION_DIAGNOSTIC,
                'Continue with contact resolve / project request create',
            );

            $ok = $response->successful();
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_DIAGNOSTIC,
                $ok ? RentmanIntegrationLog::STATUS_SUCCESS : RentmanIntegrationLog::STATUS_FAILED,
                $url . '?limit=1',
                $params,
                is_array($response->json()) ? $response->json() : ['raw' => self::truncateHttpBody($response->body())],
                $ok ? null : ('HTTP ' . $response->status()),
            );
        } catch (\Throwable $e) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_DIAGNOSTIC,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $params,
                null,
                $e->getMessage(),
            );
        }
    }

    /**
     * Resolve or create a Rentman contact for the rental requester.
     */
    public function getOrCreateContact(User $requester): string
    {
        $profile = $requester->profile ?? UserProfile::where('user_id', $requester->id)->first();
        $requesterName = self::resolveRequesterDisplayName($requester, $profile);
        $email = strtolower(trim((string) ($profile->email ?? $requester->email ?? '')));

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            RentmanIntegrationLog::ACTION_CREATE_CONTACT,
            'STARTED',
            ['name' => $requesterName !== '' ? $requesterName : null, 'email' => $email !== '' ? $email : null],
            'Retrieve Rentman contacts and match by email/name; create if not found',
        );

        $found = $this->findContactAmongAll($requesterName, $email);
        if ($found !== null) {
            Log::info('Rentman Integration: Contact Found', [
                'provider_company_id' => $this->providerCompanyId,
                'contact_id' => $found,
            ]);

            RentmanIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                RentmanIntegrationLog::ACTION_CREATE_CONTACT,
                'SUCCESS',
                ['rentman_contact_id' => $found, 'matched_by' => 'search'],
                'Resolve equipment, then create project request with linked_contact',
            );

            return $found;
        }

        $requester->loadMissing(['company.country', 'company.state', 'company.city']);
        $payload = self::buildRentmanContactCreatePayload($requester, $profile);

        $path = config('rentman.contact_create_path', '/contacts');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $response = $this->rentmanHttp(
            'POST',
            $url,
            $payload,
            RentmanIntegrationLog::ACTION_CREATE_CONTACT,
            'Link contact to project request',
        );

        $body = $response->json();
        $data = self::extractDataObject($body);

        if (!$response->successful()) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_CREATE_CONTACT,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                'HTTP ' . $response->status(),
            );

            throw new \RuntimeException(
                'Rentman contact create failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $id = $data['id'] ?? null;
        if ($id === null || $id === '') {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_CREATE_CONTACT,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : null,
                'Missing id in response',
            );

            throw new \RuntimeException('Rentman contact create: missing id in response.');
        }

        $idStr = (string) $id;

        $this->logIntegration(
            RentmanIntegrationLog::ACTION_CREATE_CONTACT,
            RentmanIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($body) ? $body : null,
            null,
        );

        Log::info('Rentman Integration: Contact Created', [
            'provider_company_id' => $this->providerCompanyId,
            'contact_id' => $idStr,
        ]);

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            RentmanIntegrationLog::ACTION_CREATE_CONTACT,
            'SUCCESS',
            ['rentman_contact_id' => $idStr, 'matched_by' => 'created'],
            'Resolve equipment, then create project request with linked_contact',
        );

        return $idStr;
    }

    /**
     * Create Rentman Project Request after contact (and equipment) have been resolved.
     * Payload includes linked_contact, contact_*, location_*, plan/usage periods, remark, etc.
     *
     * @return array{id: string, displayname: ?string}
     */
    public function createProjectRequest(
        RentalJob $rentalJob,
        string $contactId,
        SupplyJob $supplyJob,
        User $requester,
    ): array {
        if ($contactId === '') {
            throw new \RuntimeException('Rentman project request requires a resolved contact id.');
        }

        $payload = $this->buildProjectRequestPayload($rentalJob, $contactId, $supplyJob, $requester);

        if (empty($payload['planperiod_start']) || empty($payload['planperiod_end'])) {
            throw new \RuntimeException('Rentman project request requires planperiod_start and planperiod_end from rental job dates.');
        }

        $path = config('rentman.project_request_path', '/projectrequests');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            RentmanIntegrationLog::ACTION_CREATE_PROJECT_REQUEST,
            'STARTED',
            [
                'linked_contact' => $payload['linked_contact'] ?? null,
                'name' => $payload['name'] ?? null,
                'planperiod_start' => $payload['planperiod_start'] ?? null,
                'planperiod_end' => $payload['planperiod_end'] ?? null,
            ],
            'POST /projectrequests then attach equipment',
        );

        $response = $this->rentmanHttp(
            'POST',
            $url,
            $payload,
            RentmanIntegrationLog::ACTION_CREATE_PROJECT_REQUEST,
            'Attach resolved equipment lines to project request',
        );

        $body = $response->json();
        $data = self::extractDataObject($body);

        if (!$response->successful()) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_CREATE_PROJECT_REQUEST,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                'HTTP ' . $response->status(),
            );

            throw new \RuntimeException(
                'Rentman project request create failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $id = $data['id'] ?? null;
        if ($id === null || $id === '') {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_CREATE_PROJECT_REQUEST,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : null,
                'Missing id in response',
            );

            throw new \RuntimeException('Rentman project request create: missing id in response.');
        }

        $idStr = (string) $id;
        $displayname = isset($data['displayname']) ? (string) $data['displayname'] : null;

        $this->logIntegration(
            RentmanIntegrationLog::ACTION_CREATE_PROJECT_REQUEST,
            RentmanIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($body) ? $body : null,
            null,
            $idStr,
        );

        Log::info('Rentman Integration: Project Request Created', [
            'provider_company_id' => $this->providerCompanyId,
            'rental_request_id' => $this->rentalRequestId,
            'rentman_project_request_id' => $idStr,
            'displayname' => $displayname,
            'linked_contact' => $payload['linked_contact'] ?? null,
        ]);

        return ['id' => $idStr, 'displayname' => $displayname];
    }

    /**
     * Build full POST /projectrequests payload from Marketplace rental + contact data.
     *
     * @return array<string, mixed>
     */
    public function buildProjectRequestPayload(
        RentalJob $rentalJob,
        string $contactId,
        SupplyJob $supplyJob,
        User $requester,
    ): array {
        $start = self::formatPlanPeriodDateTime($rentalJob->from_date, false);
        $end = self::formatPlanPeriodDateTime($rentalJob->to_date, true);

        $name = trim((string) ($rentalJob->name ?? ''));
        if ($name === '') {
            $name = 'Rental Request #' . $rentalJob->id;
        }

        $profile = $requester->profile ?? UserProfile::where('user_id', $requester->id)->first();
        $requester->loadMissing(['company.country', 'company.state', 'company.city']);

        $payload = array_merge(
            [
                'linked_contact' => '/contacts/' . ltrim($contactId, '/'),
                'name' => $name,
                'external_reference' => (int) $rentalJob->id,
                'is_paid' => false,
                'language' => config('rentman.default_language', 'en'),
            ],
            self::buildProjectRequestContactFields($requester, $profile),
            self::buildProjectRequestLocationFields($rentalJob, $requester, $profile),
        );

        if ($start !== null) {
            $payload['planperiod_start'] = $start;
            $payload['usageperiod_start'] = $start;
            $payload['out'] = $start;
        }
        if ($end !== null) {
            $payload['planperiod_end'] = $end;
            $payload['usageperiod_end'] = $end;
            $payload['in'] = $end;
        }

        $remark = FlexIntegrationService::buildCombinedQuoteNote($rentalJob, $supplyJob);
        if ($remark !== null && $remark !== '') {
            $payload['remark'] = $remark;
        }

        // Optional total from supply job accepted/quote price when available
        $price = $supplyJob->accepted_price ?? $supplyJob->quote_price ?? null;
        if ($price !== null && $price !== '') {
            $payload['price'] = (float) $price;
        }

        return array_filter(
            $payload,
            static fn ($v) => $v !== null && $v !== '',
        );
    }

    /**
     * Contact_* fields for project request create (from Marketplace requester).
     *
     * @return array<string, mixed>
     */
    public static function buildProjectRequestContactFields(User $requester, ?UserProfile $profile): array
    {
        $firstName = trim((string) ($profile->first_name ?? ''));
        $lastName = trim((string) ($profile->last_name ?? ''));
        $email = trim((string) ($profile->email ?? $requester->email ?? ''));
        $mobile = trim((string) ($profile->mobile ?? ''));
        $fullName = self::resolveRequesterDisplayName($requester, $profile);

        $company = $requester->company;
        if ($company !== null) {
            $company->loadMissing(['country', 'state', 'city']);
        }

        $fields = [
            'contact_name' => $fullName !== '' ? $fullName : ($company?->name ?? 'Customer'),
            'contact_person_first_name' => $firstName !== '' ? $firstName : ($requester->username ?? 'Customer'),
            'contact_person_middle_name' => '',
            'contact_person_lastname' => $lastName !== '' ? $lastName : '—',
        ];

        if ($email !== '') {
            $fields['contact_person_email'] = $email;
        }
        if ($mobile !== '') {
            $fields['contact_phone'] = $mobile;
        }

        if ($company !== null) {
            $line1 = trim((string) ($company->address_line_1 ?? ''));
            $line2 = trim((string) ($company->address_line_2 ?? ''));
            if ($line1 !== '') {
                $fields['contact_mailing_street'] = $line1;
            }
            if ($line2 !== '') {
                $fields['contact_mailing_number'] = $line2;
            } elseif ($line1 !== '') {
                // Prefer line1 as street; leave number empty when not available
                $fields['contact_mailing_number'] = '';
            }
            $city = trim((string) ($company->city?->name ?? ''));
            if ($city !== '') {
                $fields['contact_mailing_city'] = $city;
            }
            $postal = trim((string) ($company->postal_code ?? ''));
            if ($postal !== '') {
                $fields['contact_mailing_postalcode'] = $postal;
            }
            $countryCode = strtolower(trim((string) ($company->country?->iso_code ?? '')));
            if ($countryCode !== '') {
                $fields['contact_mailing_country'] = $countryCode;
            }
        }

        return $fields;
    }

    /**
     * Location_* fields for project request create (delivery/event address).
     *
     * @return array<string, mixed>
     */
    public static function buildProjectRequestLocationFields(
        RentalJob $rentalJob,
        User $requester,
        ?UserProfile $profile,
    ): array {
        $location = self::buildLocationPayloadFromRentalJob($rentalJob);

        $phone = trim((string) ($profile->mobile ?? ''));
        if ($phone !== '' && !isset($location['location_phone'])) {
            $location['location_phone'] = $phone;
        }

        // If delivery address could not be parsed into structured fields, still send street as free text
        if ($location === []) {
            $delivery = trim((string) ($rentalJob->delivery_address ?? ''));
            if ($delivery !== '') {
                $location = [
                    'location_name' => trim((string) ($rentalJob->name ?? '')) !== ''
                        ? (string) $rentalJob->name
                        : 'Delivery location',
                    'location_mailing_street' => $delivery,
                ];
                if ($phone !== '') {
                    $location['location_phone'] = $phone;
                }
            }
        }

        return $location;
    }

    /**
     * Link Rentman contact to an existing project request (soft-fail returns false).
     */
    public function linkContactToProjectRequest(string $projectRequestId, string $contactId): bool
    {
        if ($projectRequestId === '' || $contactId === '') {
            return false;
        }

        $payload = [
            'linked_contact' => '/contacts/' . ltrim($contactId, '/'),
        ];

        return $this->updateProjectRequest(
            $projectRequestId,
            $payload,
            RentmanIntegrationLog::ACTION_LINK_CONTACT,
            'Link location and notes; then attach equipment',
        );
    }

    /**
     * Link delivery/event location onto the project request (soft-fail).
     */
    public function linkLocationToProjectRequest(string $projectRequestId, RentalJob $rentalJob): bool
    {
        $location = self::buildLocationPayloadFromRentalJob($rentalJob);
        if ($location === []) {
            RentmanIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                RentmanIntegrationLog::ACTION_LINK_LOCATION,
                'SKIPPED',
                ['reason' => 'no_delivery_address'],
                'Continue with notes / equipment attach',
            );

            return false;
        }

        return $this->updateProjectRequest(
            $projectRequestId,
            $location,
            RentmanIntegrationLog::ACTION_LINK_LOCATION,
            'Add marketplace messages as remark; then attach equipment',
        );
    }

    /**
     * Add marketplace messages as project request remark (soft-fail).
     * Reuses FlexIntegrationService::buildCombinedQuoteNote for message formatting.
     */
    public function addProjectRequestNoteFromRentalMessages(
        string $projectRequestId,
        RentalJob $rentalJob,
        SupplyJob $supplyJob,
    ): bool {
        $remark = FlexIntegrationService::buildCombinedQuoteNote($rentalJob, $supplyJob);
        if ($remark === null || $remark === '') {
            RentmanIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                RentmanIntegrationLog::ACTION_CREATE_NOTE,
                'SKIPPED',
                ['reason' => 'no_messages'],
                'Complete provider sync',
            );

            return false;
        }

        return $this->updateProjectRequest(
            $projectRequestId,
            ['remark' => $remark],
            RentmanIntegrationLog::ACTION_CREATE_NOTE,
            'Complete provider sync',
        );
    }

    /**
     * Resolve Rentman equipment id: inventory cache → local rentman_equipments → sync → create.
     */
    public function resolveRentmanEquipmentForProduct(
        string $displayName,
        ?string $cachedRentmanEquipmentId,
        int $productId,
        ?Equipment $inventory = null,
    ): ?string {
        $rentalRequestId = $this->rentalRequestId ?? 0;

        RentmanIntegrationDebugLog::step(
            $rentalRequestId,
            $this->providerCompanyId,
            'EQUIPMENT_RESOLVE',
            'STARTED',
            [
                'product_id' => $productId,
                'name' => $displayName,
                'cached_rentman_equipment_id' => $cachedRentmanEquipmentId,
            ],
            'Validate cached ID, search local cache, sync, or create equipment',
        );

        if ($cachedRentmanEquipmentId !== null && $cachedRentmanEquipmentId !== '') {
            if ($this->rentmanEquipmentExists($cachedRentmanEquipmentId)) {
                RentmanIntegrationDebugLog::step(
                    $rentalRequestId,
                    $this->providerCompanyId,
                    'EQUIPMENT_RESOLVE',
                    'FROM_CACHED_ID',
                    [
                        'product_id' => $productId,
                        'rentman_equipment_id' => $cachedRentmanEquipmentId,
                    ],
                    'Attach equipment to project request',
                );

                return $cachedRentmanEquipmentId;
            }

            Log::warning('Rentman Integration: Cached equipment ID invalid; falling back to search', [
                'provider_company_id' => $this->providerCompanyId,
                'product_id' => $productId,
                'rentman_equipment_id' => $cachedRentmanEquipmentId,
            ]);
        }

        $local = $this->findLocalRentmanEquipmentByName($displayName);
        if ($local !== null) {
            self::persistRentmanEquipmentOnInventory($this->providerCompanyId, $productId, $local);
            $this->logPersistEquipmentId($productId, $local, 'local_cache');

            return $local;
        }

        RentmanIntegrationDebugLog::step(
            $rentalRequestId,
            $this->providerCompanyId,
            RentmanIntegrationLog::ACTION_SYNC_EQUIPMENT,
            'STARTED',
            ['name' => $displayName],
            'Run Rentman equipment catalog sync then re-search local cache',
        );

        try {
            (new RentmanService($this->providerCompanyId))->syncAllEquipmentFromApi();
            CompanyIntegration::query()
                ->where('company_id', $this->providerCompanyId)
                ->where('integration_type', 'rentman')
                ->update([
                    'last_fetched_at' => now(),
                    'last_synced_at' => now(),
                ]);

            $this->logIntegration(
                RentmanIntegrationLog::ACTION_SYNC_EQUIPMENT,
                RentmanIntegrationLog::STATUS_SUCCESS,
                null,
                ['name' => $displayName],
                ['synced' => true],
            );
        } catch (\Throwable $e) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_SYNC_EQUIPMENT,
                RentmanIntegrationLog::STATUS_FAILED,
                null,
                ['name' => $displayName],
                null,
                $e->getMessage(),
            );
            Log::warning('Rentman Integration: Equipment sync failed during resolve', [
                'provider_company_id' => $this->providerCompanyId,
                'error' => $e->getMessage(),
            ]);
        }

        $localAfterSync = $this->findLocalRentmanEquipmentByName($displayName);
        if ($localAfterSync !== null) {
            self::persistRentmanEquipmentOnInventory($this->providerCompanyId, $productId, $localAfterSync);
            $this->logPersistEquipmentId($productId, $localAfterSync, 'after_sync');

            return $localAfterSync;
        }

        $apiMatch = $this->searchRentmanEquipmentByName($displayName);
        if ($apiMatch !== null) {
            self::persistRentmanEquipmentOnInventory($this->providerCompanyId, $productId, $apiMatch);
            $this->upsertLocalEquipmentCache($apiMatch, $displayName);
            $this->logPersistEquipmentId($productId, $apiMatch, 'api_search');

            return $apiMatch;
        }

        if (!config('rentman.create_equipment_if_missing', true)) {
            return null;
        }

        try {
            $createdId = $this->createRentmanEquipment($displayName, $inventory);
            self::persistRentmanEquipmentOnInventory($this->providerCompanyId, $productId, $createdId);
            $this->upsertLocalEquipmentCache($createdId, $displayName, $inventory);
            $this->logPersistEquipmentId($productId, $createdId, 'create');

            RentmanIntegrationDebugLog::step(
                $rentalRequestId,
                $this->providerCompanyId,
                'EQUIPMENT_RESOLVE',
                'CREATED',
                [
                    'product_id' => $productId,
                    'rentman_equipment_id' => $createdId,
                    'name' => $displayName,
                ],
                'Attach newly created equipment to project request',
            );

            return $createdId;
        } catch (\Throwable $e) {
            Log::error('Rentman Integration: Equipment create failed', [
                'provider_company_id' => $this->providerCompanyId,
                'product_id' => $productId,
                'name' => $displayName,
                'error' => $e->getMessage(),
            ]);

            RentmanIntegrationDebugLog::step(
                $rentalRequestId,
                $this->providerCompanyId,
                'EQUIPMENT_RESOLVE',
                'CREATE_FAILED',
                [
                    'product_id' => $productId,
                    'name' => $displayName,
                    'error' => $e->getMessage(),
                ],
                'Mark product missing; continue with next line',
            );

            return null;
        }
    }

    /**
     * Attach equipment line to project request.
     *
     * @return array{rentman_line_id: ?string, response: mixed}
     */
    public function attachEquipmentToProjectRequest(
        string $projectRequestId,
        string $equipmentId,
        string $displayName,
        int $quantity,
        ?float $unitPrice = null,
        ?string $externalRemark = null,
    ): array {
        if ($projectRequestId === '' || $equipmentId === '' || $quantity < 1) {
            throw new \InvalidArgumentException('Project request id, equipment id, and quantity >= 1 are required.');
        }

        $pattern = config('rentman.project_request_equipment_path_pattern', '/projectrequests/%s/projectrequestequipment');
        $path = sprintf($pattern, $projectRequestId);
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $payload = [
            'name' => $displayName,
            'quantity' => $quantity,
            'quantity_total' => $quantity,
            'linked_equipment' => '/equipment/' . ltrim($equipmentId, '/'),
        ];

        if (config('rentman.push_unit_price', true) && $unitPrice !== null) {
            $payload['unit_price'] = $unitPrice;
        }
        if ($externalRemark !== null && $externalRemark !== '') {
            $payload['external_remark'] = $externalRemark;
        }

        $response = $this->rentmanHttp(
            'POST',
            $url,
            $payload,
            RentmanIntegrationLog::ACTION_ADD_EQUIPMENT_TO_REQUEST,
            'Continue with next product line or finalize sync',
            $projectRequestId,
            $equipmentId,
        );

        $body = $response->json();
        $data = self::extractDataObject($body);

        if (!$response->successful()) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_ADD_EQUIPMENT_TO_REQUEST,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                'HTTP ' . $response->status(),
                $projectRequestId,
                $equipmentId,
            );

            throw new \RuntimeException(
                'Rentman attach equipment failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $lineId = isset($data['id']) ? (string) $data['id'] : null;

        $this->logIntegration(
            RentmanIntegrationLog::ACTION_ADD_EQUIPMENT_TO_REQUEST,
            RentmanIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($body) ? $body : null,
            null,
            $projectRequestId,
            $equipmentId,
        );

        return ['rentman_line_id' => $lineId, 'response' => $body];
    }

    public function sendMissingProductsEmail(Company $provider, RentalJob $rentalJob, array $missingLines): void
    {
        if ($missingLines === []) {
            return;
        }

        $provider->loadMissing('getDefaultcontact');
        $to = $provider->getDefaultcontact->email ?? null;
        if (!$to) {
            Log::warning('Rentman integration: cannot email provider about missing products (no default contact email).', [
                'provider_company_id' => $provider->id,
                'rental_job_id' => $rentalJob->id,
            ]);

            return;
        }

        $listHtml = '<ul>';
        foreach ($missingLines as $line) {
            $name = e($line['name'] ?? '');
            $qty = (int) ($line['quantity'] ?? 0);
            $listHtml .= '<li>' . $name . ' — quantity: ' . $qty . '</li>';
        }
        $listHtml .= '</ul>';

        $body = '<p>These requested rental products are not available in Rentman inventory:</p>' . $listHtml
            . '<p>Rental request ID: ' . (int) $rentalJob->id . '</p>'
            . '<p>Rental request name: ' . e($rentalJob->name) . '</p>';

        Mail::html($body, function ($message) use ($to) {
            $message->to($to)
                ->subject('Rentman: rental products not found in inventory');
        });

        Log::info('Rentman integration: Missing products email sent to provider', [
            'provider_company_id' => $provider->id,
            'rental_job_id' => $rentalJob->id,
        ]);
    }

    public static function persistRentmanEquipmentOnInventory(
        int $providerCompanyId,
        int $productId,
        string $rentmanEquipmentId,
    ): void {
        $updated = DB::transaction(function () use ($providerCompanyId, $productId, $rentmanEquipmentId) {
            return Equipment::query()
                ->where('company_id', $providerCompanyId)
                ->where('product_id', $productId)
                ->update(['rentman_equipment_id' => $rentmanEquipmentId]);
        });

        Log::info('Rentman Integration: Database update rentman_equipment_id', [
            'provider_company_id' => $providerCompanyId,
            'product_id' => $productId,
            'rentman_equipment_id' => $rentmanEquipmentId,
            'rows_updated' => $updated,
        ]);

        if ($updated === 0) {
            Log::warning('Rentman Integration: No company_inventory row to update with rentman_equipment_id', [
                'provider_company_id' => $providerCompanyId,
                'product_id' => $productId,
                'rentman_equipment_id' => $rentmanEquipmentId,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Protected helpers
    // -------------------------------------------------------------------------

    protected function rentmanHttp(
        string $method,
        string $url,
        ?array $payload,
        string $action,
        string $nextStep,
        ?string $rentmanProjectRequestId = null,
        ?string $rentmanEquipmentId = null,
    ): \Illuminate\Http\Client\Response {
        $method = strtoupper($method);
        $rentalRequestId = $this->rentalRequestId ?? 0;

        RentmanIntegrationDebugLog::step(
            $rentalRequestId,
            $this->providerCompanyId,
            $action,
            'REQUEST',
            [
                'http_method' => $method,
                'api_url' => $url,
                'request_payload' => $payload,
            ],
            $nextStep,
        );

        $this->logIntegration(
            $action,
            RentmanIntegrationLog::STATUS_PROCESSING,
            $url,
            $payload,
            null,
            $method . ' outbound — next: ' . $nextStep,
            $rentmanProjectRequestId,
            $rentmanEquipmentId,
        );

        try {
            $pending = Http::timeout($this->timeout)->withHeaders($this->authHeaders());

            $response = match ($method) {
                'GET' => $pending->get($url, $payload ?? []),
                'POST' => $pending->post($url, $payload ?? []),
                'PUT' => $pending->put($url, $payload ?? []),
                'PATCH' => $pending->patch($url, $payload ?? []),
                'DELETE' => $pending->delete($url, $payload ?? []),
                default => throw new \InvalidArgumentException('Unsupported Rentman HTTP method: ' . $method),
            };
        } catch (\Throwable $e) {
            RentmanIntegrationDebugLog::apiCall(
                $rentalRequestId,
                $this->providerCompanyId,
                $action,
                $method,
                $url,
                $payload,
                null,
                null,
                false,
                $nextStep,
                $e->getMessage(),
            );

            $this->logIntegration(
                RentmanIntegrationLog::ACTION_API_ERROR,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                null,
                $e->getMessage(),
                $rentmanProjectRequestId,
                $rentmanEquipmentId,
            );

            throw $e;
        }

        $json = $response->json();
        $bodyForLog = is_array($json) ? $json : ['raw' => self::truncateHttpBody($response->body())];
        $success = $response->successful();

        RentmanIntegrationDebugLog::apiCall(
            $rentalRequestId,
            $this->providerCompanyId,
            $action,
            $method,
            $url,
            $payload,
            $response->status(),
            $bodyForLog,
            $success,
            $nextStep,
            $success ? null : ('HTTP ' . $response->status()),
        );

        return $response;
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->authToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    protected function logIntegration(
        string $action,
        string $status,
        ?string $requestUrl = null,
        mixed $requestPayload = null,
        mixed $responsePayload = null,
        ?string $errorMessage = null,
        ?string $rentmanProjectRequestId = null,
        ?string $rentmanEquipmentId = null,
    ): void {
        if ($this->rentmanLogger === null) {
            return;
        }

        $this->rentmanLogger->log(
            $action,
            $status,
            $requestUrl,
            $requestPayload,
            $responsePayload,
            $errorMessage,
            $rentmanProjectRequestId,
            $rentmanEquipmentId,
        );
    }

    protected function updateProjectRequest(
        string $projectRequestId,
        array $payload,
        string $action,
        string $nextStep,
    ): bool {
        $path = config('rentman.project_request_path', '/projectrequests') . '/' . $projectRequestId;
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        try {
            $response = $this->rentmanHttp(
                'PUT',
                $url,
                $payload,
                $action,
                $nextStep,
                $projectRequestId,
            );

            $body = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    $action,
                    RentmanIntegrationLog::STATUS_FAILED,
                    $url,
                    $payload,
                    is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                    'HTTP ' . $response->status(),
                    $projectRequestId,
                );

                return false;
            }

            $this->logIntegration(
                $action,
                RentmanIntegrationLog::STATUS_SUCCESS,
                $url,
                $payload,
                is_array($body) ? $body : null,
                null,
                $projectRequestId,
            );

            return true;
        } catch (\Throwable $e) {
            $this->logIntegration(
                $action,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                null,
                $e->getMessage(),
                $projectRequestId,
            );

            return false;
        }
    }

    protected function findContactAmongAll(string $name, string $email): ?string
    {
        $path = config('rentman.contact_list_path', '/contacts');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $limit = (int) config('rentman.contact_list_limit', 100);
        $maxPages = (int) config('rentman.contact_list_max_pages', 50);
        $offset = 0;
        $nameLower = strtolower(trim($name));

        for ($page = 0; $page < $maxPages; $page++) {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
            ];

            try {
                $response = $this->rentmanHttp(
                    'GET',
                    $url,
                    $params,
                    RentmanIntegrationLog::ACTION_SEARCH_CONTACT,
                    'Match contact by email/name or create contact',
                );
            } catch (\Throwable $e) {
                Log::warning('Rentman Integration: Contact list failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            $body = $response->json();
            if (!$response->successful()) {
                $this->logIntegration(
                    RentmanIntegrationLog::ACTION_SEARCH_CONTACT,
                    RentmanIntegrationLog::STATUS_FAILED,
                    $url,
                    $params,
                    is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                    'HTTP ' . $response->status(),
                );

                return null;
            }

            $items = self::extractListItems($body);
            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $matched = $this->contactMatches($item, $nameLower, $email);
                if ($matched !== null) {
                    $this->logIntegration(
                        RentmanIntegrationLog::ACTION_SEARCH_CONTACT,
                        RentmanIntegrationLog::STATUS_SUCCESS,
                        $url,
                        ['matched_id' => $matched, 'name' => $name, 'email' => $email],
                        ['id' => $matched],
                    );

                    return $matched;
                }
            }

            if (count($items) < $limit) {
                break;
            }
            $offset += $limit;
        }

        $this->logIntegration(
            RentmanIntegrationLog::ACTION_SEARCH_CONTACT,
            RentmanIntegrationLog::STATUS_SUCCESS,
            $url,
            ['name' => $name, 'email' => $email],
            ['matched' => false],
        );

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function contactMatches(array $item, string $nameLower, string $email): ?string
    {
        $id = $item['id'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }

        $contactEmails = [];
        foreach (['email_1', 'email', 'email1'] as $key) {
            $v = strtolower(trim((string) ($item[$key] ?? '')));
            if ($v !== '') {
                $contactEmails[] = $v;
            }
        }

        $persons = $item['contactpersons'] ?? $item['contact_persons'] ?? null;
        if (is_array($persons)) {
            foreach ($persons as $person) {
                if (!is_array($person)) {
                    continue;
                }
                foreach (['email_1', 'email', 'email1'] as $key) {
                    $v = strtolower(trim((string) ($person[$key] ?? '')));
                    if ($v !== '') {
                        $contactEmails[] = $v;
                    }
                }
            }
        }

        if ($email !== '' && in_array($email, $contactEmails, true)) {
            return (string) $id;
        }

        if ($nameLower === '') {
            return null;
        }

        $candidates = [
            strtolower(trim((string) ($item['name'] ?? ''))),
            trim(strtolower(trim((string) ($item['firstname'] ?? '')) . ' ' . strtolower(trim((string) ($item['surname'] ?? ''))))),
            trim(strtolower(trim((string) ($item['firstname'] ?? '')) . ' ' . strtolower(trim((string) ($item['lastname'] ?? ''))))),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $candidate === $nameLower) {
                return (string) $id;
            }
        }

        return null;
    }

    protected function findLocalRentmanEquipmentByName(string $displayName): ?string
    {
        $trimmed = trim($displayName);
        if ($trimmed === '') {
            return null;
        }

        $escaped = RentmanEquipment::escapeLike($trimmed);

        $row = RentmanEquipment::query()
            ->where('company_id', $this->providerCompanyId)
            ->where(function ($q) use ($escaped, $trimmed) {
                $q->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
                    ->orWhereRaw('LOWER(displayname) = ?', [strtolower($trimmed)])
                    ->orWhere('name', 'LIKE', $escaped)
                    ->orWhere('displayname', 'LIKE', $escaped);
            })
            ->orderByRaw(
                '(CASE WHEN LOWER(COALESCE(name, \'\')) = ? OR LOWER(COALESCE(displayname, \'\')) = ? THEN 0 ELSE 1 END)',
                [strtolower($trimmed), strtolower($trimmed)]
            )
            ->first();

        return $row?->rentman_id !== null && $row->rentman_id !== '' ? (string) $row->rentman_id : null;
    }

    protected function searchRentmanEquipmentByName(string $displayName): ?string
    {
        $path = config('rentman.equipment_path', '/equipment');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $params = [
            'name' => $displayName,
            'fields' => config('rentman.equipment_search_fields', 'id,name,displayname,code,updateHash'),
            'limit' => 10,
        ];

        try {
            $response = $this->rentmanHttp(
                'GET',
                $url,
                $params,
                RentmanIntegrationLog::ACTION_SEARCH_EQUIPMENT,
                'Use matched equipment or create if missing',
            );
        } catch (\Throwable $e) {
            return null;
        }

        $body = $response->json();
        if (!$response->successful()) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_SEARCH_EQUIPMENT,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $params,
                is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                'HTTP ' . $response->status(),
            );

            return null;
        }

        $items = self::extractListItems($body);
        $needle = strtolower(trim($displayName));

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            $display = strtolower(trim((string) ($item['displayname'] ?? $item['displayName'] ?? '')));
            if ($name === $needle || $display === $needle || str_contains($name, $needle) || str_contains($display, $needle)) {
                $this->logIntegration(
                    RentmanIntegrationLog::ACTION_SEARCH_EQUIPMENT,
                    RentmanIntegrationLog::STATUS_SUCCESS,
                    $url,
                    $params,
                    ['matched_id' => (string) $id],
                );

                return (string) $id;
            }
        }

        if ($items !== [] && is_array($items[0]) && isset($items[0]['id'])) {
            return (string) $items[0]['id'];
        }

        return null;
    }

    protected function rentmanEquipmentExists(string $equipmentId): bool
    {
        $path = config('rentman.equipment_path', '/equipment') . '/' . ltrim($equipmentId, '/');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        try {
            $response = $this->rentmanHttp(
                'GET',
                $url,
                null,
                RentmanIntegrationLog::ACTION_VALIDATE_EQUIPMENT,
                'Reuse cached equipment or fall back to search',
                null,
                $equipmentId,
            );

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function createRentmanEquipment(string $displayName, ?Equipment $inventory): string
    {
        $path = config('rentman.equipment_path', '/equipment');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $payload = [
            'name' => $displayName,
            'type' => 'item',
            'rental_sales' => 'Rental',
            'is_physical' => 'Physical equipment',
        ];

        if ($inventory !== null) {
            $qty = (int) ($inventory->quantity ?? 0);
            if ($qty > 0) {
                $payload['unit'] = (string) $qty;
            }
            if ($inventory->rental_price !== null) {
                $payload['price'] = (float) $inventory->rental_price;
            }
            if ($inventory->replacement_price !== null) {
                $payload['subrental_costs'] = (float) $inventory->replacement_price;
            }
            foreach (['height', 'width', 'length', 'weight'] as $dim) {
                if ($inventory->{$dim} !== null) {
                    $payload[$dim] = (float) $inventory->{$dim};
                }
            }
            $coo = strtolower(trim((string) ($inventory->country_of_origin ?? '')));
            if ($coo !== '') {
                $payload['country_of_origin'] = $coo;
            }
        }

        $response = $this->rentmanHttp(
            'POST',
            $url,
            $payload,
            RentmanIntegrationLog::ACTION_CREATE_EQUIPMENT,
            'Persist rentman_equipment_id and attach to project request',
        );

        $body = $response->json();
        $data = self::extractDataObject($body);

        if (!$response->successful()) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_CREATE_EQUIPMENT,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                'HTTP ' . $response->status(),
            );

            throw new \RuntimeException(
                'Rentman equipment create failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $id = $data['id'] ?? null;
        if ($id === null || $id === '') {
            throw new \RuntimeException('Rentman equipment create: missing id in response.');
        }

        $idStr = (string) $id;

        $this->logIntegration(
            RentmanIntegrationLog::ACTION_CREATE_EQUIPMENT,
            RentmanIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($body) ? $body : null,
            null,
            null,
            $idStr,
        );

        return $idStr;
    }

    protected function upsertLocalEquipmentCache(string $rentmanId, string $displayName, ?Equipment $inventory = null): void
    {
        RentmanEquipment::query()->updateOrCreate(
            [
                'company_id' => $this->providerCompanyId,
                'rentman_id' => $rentmanId,
            ],
            [
                'name' => $displayName,
                'displayname' => $displayName,
                'height' => $inventory?->height,
                'width' => $inventory?->width,
                'length' => $inventory?->length,
                'weight' => $inventory?->weight,
                'country_of_origin' => $inventory?->country_of_origin,
                'current_quantity' => $inventory?->quantity,
                'synced_at' => now(),
            ],
        );
    }

    protected function logPersistEquipmentId(int $productId, string $rentmanEquipmentId, string $source): void
    {
        $this->logIntegration(
            RentmanIntegrationLog::ACTION_PERSIST_EQUIPMENT_ID,
            RentmanIntegrationLog::STATUS_SUCCESS,
            null,
            [
                'product_id' => $productId,
                'rentman_equipment_id' => $rentmanEquipmentId,
                'source' => $source,
                'table' => 'company_inventory',
            ],
            null,
            null,
            null,
            $rentmanEquipmentId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildLocationPayloadFromRentalJob(RentalJob $rentalJob): array
    {
        $delivery = trim((string) ($rentalJob->delivery_address ?? ''));
        if ($delivery === '') {
            return [];
        }

        $payload = [
            'location_name' => trim((string) ($rentalJob->name ?? '')) !== ''
                ? (string) $rentalJob->name
                : 'Delivery location',
        ];

        // Best-effort parse: "street number, city, state postal, country"
        $parts = array_values(array_filter(array_map('trim', explode(',', $delivery))));
        $streetPart = $parts[0] ?? $delivery;

        if (preg_match('/^(.*?)[\s,]+(\d+[a-zA-Z]?)$/', $streetPart, $m)) {
            $payload['location_mailing_street'] = trim($m[1]);
            $payload['location_mailing_number'] = trim($m[2]);
        } elseif (preg_match('/^(\d+[a-zA-Z]?)\s+(.*)$/', $streetPart, $m)) {
            $payload['location_mailing_number'] = trim($m[1]);
            $payload['location_mailing_street'] = trim($m[2]);
        } else {
            $payload['location_mailing_street'] = $streetPart;
        }

        if (count($parts) >= 2) {
            $payload['location_mailing_city'] = $parts[1] ?? null;
        }
        if (count($parts) >= 3) {
            $stateOrPostal = $parts[count($parts) >= 4 ? count($parts) - 2 : 2] ?? '';
            if (preg_match('/\b(\d{4,10})\b/', $stateOrPostal, $m)) {
                $payload['location_mailing_postalcode'] = $m[1];
            }
            $payload['location_mailing_country'] = $parts[count($parts) - 1];
        }

        return array_filter($payload, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function buildRentmanContactCreatePayload(User $requester, ?UserProfile $profile): array
    {
        $firstName = trim((string) ($profile->first_name ?? ''));
        $lastName = trim((string) ($profile->last_name ?? ''));
        $email = trim((string) ($profile->email ?? $requester->email ?? ''));
        $mobile = trim((string) ($profile->mobile ?? ''));
        $fullName = self::resolveRequesterDisplayName($requester, $profile);

        $company = $requester->company;
        if ($company !== null) {
            $company->loadMissing(['country', 'state', 'city']);
        }

        $payload = [
            'folder' => config('rentman.contact_folder', '/folders/0'),
            'type' => config('rentman.contact_type', 'private'),
            'firstname' => $firstName !== '' ? $firstName : ($requester->username ?? 'Customer'),
            'surname' => $lastName !== '' ? $lastName : '—',
            'name' => $fullName !== '' ? $fullName : trim(($firstName ?: 'Customer') . ' ' . ($lastName ?: '')),
            'default_person' => '/contactpersons/0',
            'admin_contactperson' => '/contactpersons/0',
        ];

        if ($email !== '') {
            $payload['email_1'] = $email;
        }
        if ($mobile !== '') {
            $payload['phone_1'] = $mobile;
        }

        if ($company !== null) {
            $line1 = trim((string) ($company->address_line_1 ?? ''));
            $line2 = trim((string) ($company->address_line_2 ?? ''));
            $street = trim($line1 . ($line2 !== '' ? ' ' . $line2 : ''));
            if ($street !== '') {
                $payload['visit_street'] = $street;
            }
            $city = trim((string) ($company->city?->name ?? ''));
            if ($city !== '') {
                $payload['visit_city'] = $city;
            }
            $state = trim((string) ($company->state?->name ?? ''));
            if ($state !== '') {
                $payload['visit_state'] = $state;
            }
            $postal = trim((string) ($company->postal_code ?? ''));
            if ($postal !== '') {
                $payload['visit_postalcode'] = $postal;
            }
            $countryCode = strtolower(trim((string) ($company->country?->iso_code ?? '')));
            if ($countryCode !== '') {
                $payload['country'] = $countryCode;
            }
            if ($company->latitude !== null) {
                $payload['latitude'] = (float) $company->latitude;
            }
            if ($company->longitude !== null) {
                $payload['longitude'] = (float) $company->longitude;
            }
        }

        return $payload;
    }

    protected static function resolveRequesterDisplayName(User $requester, ?UserProfile $profile): string
    {
        $fromProfile = trim((string) ($profile->full_name ?? ''));
        if ($fromProfile !== '') {
            return $fromProfile;
        }

        $composed = trim(
            trim((string) ($profile->first_name ?? '')) . ' ' . trim((string) ($profile->last_name ?? ''))
        );
        if ($composed !== '') {
            return $composed;
        }

        return trim((string) ($requester->username ?? $requester->name ?? ''));
    }

    protected static function formatPlanPeriodDateTime(mixed $date, bool $endOfDay): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $c = Carbon::parse($date)->timezone('UTC');
        $c = $endOfDay ? $c->copy()->setTime(12, 0, 0) : $c->copy()->setTime(12, 0, 0);
        $format = config('rentman.planperiod_datetime_format', 'Y-m-d\TH:i:s\Z');

        return $c->format($format);
    }

    /**
     * @param  mixed  $body
     * @return array<string, mixed>
     */
    protected static function extractDataObject(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }
        if (isset($body['data']) && is_array($body['data']) && !array_is_list($body['data'])) {
            return $body['data'];
        }
        if (isset($body['id'])) {
            return $body;
        }

        return [];
    }

    /**
     * @param  mixed  $body
     * @return array<int, mixed>
     */
    protected static function extractListItems(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }

        $items = $body['data'] ?? $body['items'] ?? $body['results'] ?? null;
        if ($items === null && array_is_list($body)) {
            return $body;
        }

        return is_array($items) ? $items : [];
    }

    protected static function truncateHttpBody(string $body, int $maxChars = 8000): string
    {
        if (strlen($body) <= $maxChars) {
            return $body;
        }

        return substr($body, 0, $maxChars) . '… [truncated, total ' . strlen($body) . ' bytes]';
    }
}
