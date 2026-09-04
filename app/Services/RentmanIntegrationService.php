<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Country;
use App\Models\Equipment;
use App\Models\RentalJob;
use App\Models\RentalJobComment;
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
     * Search by requester company name; if not found, create company contact + person + default/admin.
     */
    public function getOrCreateContact(User $requester): string
    {
        $profile = $requester->profile ?? UserProfile::where('user_id', $requester->id)->first();
        $requester->loadMissing(['company']);
        $companyName = trim((string) ($requester->company?->name ?? ''));

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            RentmanIntegrationLog::ACTION_CREATE_CONTACT,
            'STARTED',
            ['company_name' => $companyName !== '' ? $companyName : null],
            'Search Rentman contacts by company name; create company contact if not found',
        );

        if ($companyName !== '') {
            $found = $this->findContactByCompanyName($companyName);
            if ($found !== null) {
                Log::info('Rentman Integration: Contact Found', [
                    'provider_company_id' => $this->providerCompanyId,
                    'contact_id' => $found,
                    'matched_by' => 'company_name',
                    'company_name' => $companyName,
                ]);

                RentmanIntegrationDebugLog::step(
                    $this->rentalRequestId ?? 0,
                    $this->providerCompanyId,
                    RentmanIntegrationLog::ACTION_CREATE_CONTACT,
                    'SUCCESS',
                    [
                        'rentman_contact_id' => $found,
                        'matched_by' => 'company_name',
                        'company_name' => $companyName,
                    ],
                    'Resolve equipment, then create project request with linked_contact',
                );

                return $found;
            }
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
            'Create contact person, then set default/admin person',
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

        $contactPersonId = $this->createContactPerson($idStr, $requester, $profile);
        $this->updateContactDefaultPersons($idStr, $contactPersonId);

        Log::info('Rentman Integration: Contact Created', [
            'provider_company_id' => $this->providerCompanyId,
            'contact_id' => $idStr,
            'contact_person_id' => $contactPersonId,
        ]);

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            RentmanIntegrationLog::ACTION_CREATE_CONTACT,
            'SUCCESS',
            [
                'rentman_contact_id' => $idStr,
                'rentman_contact_person_id' => $contactPersonId,
                'matched_by' => 'created',
            ],
            'Resolve equipment, then create project request with linked_contact',
        );

        return $idStr;
    }

    /**
     * POST /contacts/{id}/contactpersons — attach requester as contact person.
     */
    protected function createContactPerson(
        string $contactId,
        User $requester,
        ?UserProfile $profile,
    ): string {
        $payload = self::buildRentmanContactPersonCreatePayload($requester, $profile);
        $pattern = config('rentman.contact_person_path_pattern', '/contacts/%s/contactpersons');
        $path = sprintf($pattern, $contactId);
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            RentmanIntegrationLog::ACTION_CREATE_CONTACT_PERSON,
            'STARTED',
            ['contact_id' => $contactId, 'firstname' => $payload['firstname'] ?? null],
            'Set default_person and admin_contactperson on contact',
        );

        $response = $this->rentmanHttp(
            'POST',
            $url,
            $payload,
            RentmanIntegrationLog::ACTION_CREATE_CONTACT_PERSON,
            'Update contact default/admin person',
        );

        $body = $response->json();
        $data = self::extractDataObject($body);

        if (!$response->successful()) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_CREATE_CONTACT_PERSON,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                'HTTP ' . $response->status(),
            );

            throw new \RuntimeException(
                'Rentman contact person create failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $personId = $data['id'] ?? null;
        if ($personId === null || $personId === '') {
            throw new \RuntimeException('Rentman contact person create: missing id in response.');
        }

        $personIdStr = (string) $personId;

        $this->logIntegration(
            RentmanIntegrationLog::ACTION_CREATE_CONTACT_PERSON,
            RentmanIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($body) ? $body : null,
            null,
        );

        return $personIdStr;
    }

    /**
     * PUT /contacts/{id} — set default_person and admin_contactperson.
     */
    protected function updateContactDefaultPersons(string $contactId, string $contactPersonId): void
    {
        $personPath = '/contactpersons/' . ltrim($contactPersonId, '/');
        $payload = [
            'default_person' => $personPath,
            'admin_contactperson' => $personPath,
        ];

        $path = config('rentman.contact_create_path', '/contacts') . '/' . $contactId;
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            RentmanIntegrationLog::ACTION_UPDATE_CONTACT,
            'STARTED',
            ['contact_id' => $contactId, 'contact_person_id' => $contactPersonId],
            'Continue with equipment resolve / project request',
        );

        $response = $this->rentmanHttp(
            'PUT',
            $url,
            $payload,
            RentmanIntegrationLog::ACTION_UPDATE_CONTACT,
            'Continue with equipment resolve / project request',
        );

        $body = $response->json();

        if (!$response->successful()) {
            $this->logIntegration(
                RentmanIntegrationLog::ACTION_UPDATE_CONTACT,
                RentmanIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : ['raw' => self::truncateHttpBody($response->body())],
                'HTTP ' . $response->status(),
            );

            throw new \RuntimeException(
                'Rentman contact update (default/admin person) failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $this->logIntegration(
            RentmanIntegrationLog::ACTION_UPDATE_CONTACT,
            RentmanIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($body) ? $body : null,
            null,
        );
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

        $remark = self::buildProjectRequestRemark($rentalJob, $supplyJob);
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
     */
    public function addProjectRequestNoteFromRentalMessages(
        string $projectRequestId,
        RentalJob $rentalJob,
        SupplyJob $supplyJob,
    ): bool {
        $remark = self::buildProjectRequestRemark($rentalJob, $supplyJob);
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
     * Combine Global Message, Offer Requirements, and Private Message for Rentman remark.
     * Empty/null sections are omitted; remaining values are joined with " | ".
     */
    public static function buildProjectRequestRemark(RentalJob $rentalJob, SupplyJob $supplyJob): ?string
    {
        $globalMessage = trim((string) ($rentalJob->global_message ?? ''));
        $offerRequirements = trim((string) ($rentalJob->offer_requirements ?? ''));

        $privateMessage = trim((string) (
            RentalJobComment::query()
                ->where('supply_job_id', $supplyJob->id)
                ->where('is_private', true)
                ->orderBy('id')
                ->value('message') ?? ''
        ));

        $parts = [];
        if ($globalMessage !== '') {
            $parts[] = $globalMessage;
        }
        if ($offerRequirements !== '') {
            $parts[] = $offerRequirements;
        }
        if ($privateMessage !== '') {
            $parts[] = $privateMessage;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' | ', $parts);
    }

    /**
     * Resolve Rentman equipment id for a rental-request line.
     *
     * Already-linked inventory reuses rentman_equipment_id (no search/create).
     * Unlinked products use marketplace search matching, then:
     * - 1 match: link that equipment and push latest PSM details
     * - multiple matches: create a new Rentman equipment (do not pick one)
     * - 0 matches: return null (caller adds a name-only project-request line)
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

        $matches = $this->collectRentmanMatchesForRentalRequest($displayName, $inventory);
        $matchCount = count($matches);

        RentmanIntegrationDebugLog::step(
            $rentalRequestId,
            $this->providerCompanyId,
            'EQUIPMENT_RESOLVE',
            'SEARCH_RESULT',
            [
                'product_id' => $productId,
                'name' => $displayName,
                'match_count' => $matchCount,
            ],
            $matchCount === 1
                ? 'Sync PSM details onto the matched Rentman equipment'
                : ($matchCount > 1
                    ? 'Create a new Rentman equipment because matches are ambiguous'
                    : 'Add product to the project request by name (do not create equipment)'),
        );

        if ($matchCount === 1) {
            $matchedId = (string) ($matches[0]['resource_id'] ?? '');
            if ($matchedId === '') {
                return null;
            }

            $this->syncPsmInventoryDetailsToRentmanEquipment($matchedId, $displayName, $inventory);
            self::persistRentmanEquipmentOnInventory($this->providerCompanyId, $productId, $matchedId);
            $this->upsertLocalEquipmentCache($matchedId, $displayName, $inventory);
            $this->logPersistEquipmentId($productId, $matchedId, 'single_match');

            RentmanIntegrationDebugLog::step(
                $rentalRequestId,
                $this->providerCompanyId,
                'EQUIPMENT_RESOLVE',
                'SINGLE_MATCH',
                [
                    'product_id' => $productId,
                    'rentman_equipment_id' => $matchedId,
                    'name' => $displayName,
                ],
                'Attach matched equipment to project request',
            );

            return $matchedId;
        }

        if ($matchCount > 1) {
            return $this->createAndLinkRentmanEquipmentForResolve(
                $displayName,
                $productId,
                $inventory,
                'multiple_matches',
            );
        }

        RentmanIntegrationDebugLog::step(
            $rentalRequestId,
            $this->providerCompanyId,
            'EQUIPMENT_RESOLVE',
            'NO_MATCH',
            [
                'product_id' => $productId,
                'name' => $displayName,
            ],
            'Attach product to the Rentman project request by name after it is created',
        );

        return null;
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

    /**
     * Add a product to a Rentman project request by name only (no linked equipment).
     * Used when the product was not found in the Rentman catalog.
     *
     * @return array{rentman_line_id: ?string, response: mixed}
     */
    public function attachUnlinkedProductNameToProjectRequest(
        string $projectRequestId,
        string $displayName,
    ): array {
        $displayName = trim($displayName);
        if ($projectRequestId === '' || $displayName === '') {
            throw new \InvalidArgumentException('Project request id and product name are required.');
        }

        $pattern = config('rentman.project_request_equipment_path_pattern', '/projectrequests/%s/projectrequestequipment');
        $path = sprintf($pattern, $projectRequestId);
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $payload = [
            'name' => $displayName,
        ];

        $response = $this->rentmanHttp(
            'POST',
            $url,
            $payload,
            RentmanIntegrationLog::ACTION_ADD_EQUIPMENT_TO_REQUEST,
            'Continue with next product line or finalize sync',
            $projectRequestId,
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
            );

            throw new \RuntimeException(
                'Rentman attach equipment by name failed: HTTP ' . $response->status() . ' — '
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

    /**
     * Marketplace Inventory → Rentman search (local cache → sync → re-search; no DB updates).
     *
     * @return array{
     *   success: bool,
     *   action: string,
     *   message?: string,
     *   products?: list<array{resource_id: string, name: string}>
     * }
     *
     * @throws \InvalidArgumentException When product/name data is missing
     */
    public function searchRentmanProductForMarketplace(Equipment $equipment): array
    {
        $displayName = $this->resolveMarketplaceProductDisplayName($equipment);

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'SEARCH_RENTMAN_PRODUCT',
            'STARTED',
            [
                'company_inventory_id' => $equipment->id,
                'company_id' => $equipment->company_id,
                'product_id' => $equipment->product_id,
                'name' => $displayName,
            ],
            'Search local rentman_equipments; sync catalog if empty; re-search; no database updates',
        );

        $matches = $this->collectLocalRentmanEquipmentMatches($displayName);

        if ($matches === []) {
            $this->triggerEquipmentCatalogSync($displayName);
            $matches = $this->collectLocalRentmanEquipmentMatches($displayName);
        }

        $products = array_map(static function (array $match): array {
            return [
                'resource_id' => $match['resource_id'],
                'name' => $match['name'],
            ];
        }, $matches);

        if ($products === []) {
            Log::info('Rentman Integration: No matching Rentman product found for marketplace search', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'name' => $displayName,
            ]);

            RentmanIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'SEARCH_RENTMAN_PRODUCT',
                'NO_MATCH',
                [
                    'company_inventory_id' => $equipment->id,
                    'name' => $displayName,
                ],
                'Await user confirmation to create equipment in Rentman',
            );

            return [
                'success' => true,
                'action' => 'no_match',
                'message' => 'No matching product found in Rentman.',
            ];
        }

        $action = count($products) === 1 ? 'single_match' : 'multiple_matches';
        $message = $action === 'single_match'
            ? 'Matching product found in Rentman.'
            : 'Multiple matching products found in Rentman. Please select one to synchronize.';

        Log::info('Rentman Integration: Rentman marketplace search completed', [
            'provider_company_id' => $this->providerCompanyId,
            'company_inventory_id' => $equipment->id,
            'product_id' => $equipment->product_id,
            'action' => $action,
            'match_count' => count($products),
            'products' => $products,
        ]);

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'SEARCH_RENTMAN_PRODUCT',
            strtoupper($action),
            [
                'company_inventory_id' => $equipment->id,
                'match_count' => count($products),
                'products' => $products,
            ],
            'Await user confirmation before linking Rentman equipment',
        );

        return [
            'success' => true,
            'action' => $action,
            'products' => $products,
            'message' => $message,
        ];
    }

    /**
     * Confirm marketplace Rentman sync: link equipment ID or create in Rentman from inventory data.
     *
     * @return array{success: bool, action: string, message: string, resource_id: string}
     *
     * @throws \InvalidArgumentException When product/name data is missing
     * @throws \RuntimeException When Rentman create fails or other API errors occur
     */
    public function confirmRentmanMarketplaceSync(
        Equipment $equipment,
        ?string $resourceId,
        bool $createIfMissing,
    ): array {
        $displayName = $this->resolveMarketplaceProductDisplayName($equipment);

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'CONFIRM_RENTMAN_SYNC',
            'STARTED',
            [
                'company_inventory_id' => $equipment->id,
                'company_id' => $equipment->company_id,
                'product_id' => $equipment->product_id,
                'name' => $displayName,
                'resource_id' => $resourceId,
                'create_if_missing' => $createIfMissing,
            ],
            $createIfMissing
                ? 'Create equipment in Rentman then link to company_inventory'
                : 'Link Rentman equipment ID to company_inventory',
        );

        if ($createIfMissing) {
            // Re-check local cache (and sync once more) before creating, matching the required workflow.
            $existing = $this->findLocalRentmanEquipmentByName($displayName);
            if ($existing === null) {
                $this->triggerEquipmentCatalogSync($displayName);
                $existing = $this->findLocalRentmanEquipmentByName($displayName);
            }

            if ($existing !== null) {
                Log::info('Rentman Integration: Marketplace create skipped; match found after re-search', [
                    'provider_company_id' => $this->providerCompanyId,
                    'company_inventory_id' => $equipment->id,
                    'product_id' => $equipment->product_id,
                    'rentman_equipment_id' => $existing,
                    'name' => $displayName,
                ]);

                return $this->attemptLinkRentmanEquipmentToEquipment($equipment, $existing, 'search');
            }

            $createdId = $this->createRentmanEquipment($displayName, $equipment);
            $this->upsertLocalEquipmentCache($createdId, $displayName, $equipment);

            Log::info('Rentman Integration: New Rentman equipment created for marketplace confirm sync', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'rentman_equipment_id' => $createdId,
                'name' => $displayName,
            ]);

            return $this->attemptLinkRentmanEquipmentToEquipment($equipment, $createdId, 'create');
        }

        $resourceId = trim((string) $resourceId);
        if ($resourceId === '') {
            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => '',
                'message' => 'resource_id is required when create_if_missing is false.',
            ];
        }

        return $this->attemptLinkRentmanEquipmentToEquipment($equipment, $resourceId, 'search');
    }

    // -------------------------------------------------------------------------
    // Protected helpers
    // -------------------------------------------------------------------------

    /**
     * @throws \InvalidArgumentException
     */
    protected function resolveMarketplaceProductDisplayName(Equipment $equipment): string
    {
        $equipment->loadMissing(['product.brand']);

        $product = $equipment->product;
        if (!$product) {
            throw new \InvalidArgumentException('Product not found for this company inventory record.');
        }

        $displayName = FlexIntegrationService::productDisplayName($product);
        if (trim($displayName) === '') {
            throw new \InvalidArgumentException('Product name is empty; cannot search or create in Rentman.');
        }

        return $displayName;
    }

    /**
     * Rental-request search: reuse marketplace matching (local cache → catalog sync → re-search).
     *
     * @return list<array{resource_id: string, name: string}>
     */
    protected function collectRentmanMatchesForRentalRequest(string $displayName, ?Equipment $inventory): array
    {
        if ($inventory !== null) {
            try {
                $result = $this->searchRentmanProductForMarketplace($inventory);

                return $result['products'] ?? [];
            } catch (\InvalidArgumentException) {
                // Fall through to display-name search when inventory product data is incomplete.
            }
        }

        $matches = $this->collectLocalRentmanEquipmentMatches($displayName);
        if ($matches === []) {
            $this->triggerEquipmentCatalogSync($displayName);
            $matches = $this->collectLocalRentmanEquipmentMatches($displayName);
        }

        return $matches;
    }

    /**
     * Collect unique local rentman_equipments matches for a marketplace product name.
     *
     * Matching order:
     * 1) exact name/displayname (case-insensitive)
     * 2) LIKE %name% on name/displayname
     * 3) alphanumeric-normalized containment (handles "L-Acoustics" vs "L Acoustics")
     * 4) all significant tokens present in name/displayname
     *
     * @return list<array{resource_id: string, name: string}>
     */
    protected function collectLocalRentmanEquipmentMatches(string $displayName): array
    {
        $trimmed = trim($displayName);
        if ($trimmed === '') {
            return [];
        }

        $escaped = RentmanEquipment::escapeLike($trimmed);
        $needleLower = strtolower($trimmed);
        $normalizedNeedle = self::normalizeEquipmentSearchKey($trimmed);
        $tokens = self::equipmentSearchTokens($trimmed);

        $rows = RentmanEquipment::query()
            ->where('company_id', $this->providerCompanyId)
            ->where(function ($q) use ($escaped, $needleLower, $normalizedNeedle, $tokens) {
                $q->whereRaw('LOWER(name) = ?', [$needleLower])
                    ->orWhereRaw('LOWER(displayname) = ?', [$needleLower])
                    ->orWhere('name', 'LIKE', '%' . $escaped . '%')
                    ->orWhere('displayname', 'LIKE', '%' . $escaped . '%');

                if ($normalizedNeedle !== '') {
                    $q->orWhereRaw(
                        "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', '')) LIKE ?",
                        ['%' . $normalizedNeedle . '%']
                    )->orWhereRaw(
                        "LOWER(REPLACE(REPLACE(REPLACE(COALESCE(displayname, ''), '-', ''), ' ', ''), '_', '')) LIKE ?",
                        ['%' . $normalizedNeedle . '%']
                    );
                }

                if ($tokens !== []) {
                    $q->orWhere(function ($tokenQuery) use ($tokens) {
                        foreach ($tokens as $token) {
                            $tokenEscaped = RentmanEquipment::escapeLike($token);
                            $tokenQuery->where(function ($fieldQuery) use ($tokenEscaped) {
                                $fieldQuery->where('name', 'LIKE', '%' . $tokenEscaped . '%')
                                    ->orWhere('displayname', 'LIKE', '%' . $tokenEscaped . '%');
                            });
                        }
                    });
                }
            })
            ->orderByRaw(
                '(CASE WHEN LOWER(COALESCE(name, \'\')) = ? OR LOWER(COALESCE(displayname, \'\')) = ? THEN 0 ELSE 1 END)',
                [$needleLower, $needleLower]
            )
            ->limit(20)
            ->get();

        $products = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row->rentman_id ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $products[] = [
                'resource_id' => $id,
                'name' => RentmanService::primaryLabel($row) ?: $trimmed,
            ];
        }

        return $products;
    }

    /**
     * Normalize equipment names for tolerant matching (lowercase, strip separators).
     */
    protected static function normalizeEquipmentSearchKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';

        return $normalized;
    }

    /**
     * Significant tokens for AND matching (keeps model codes like SB28).
     *
     * @return list<string>
     */
    protected static function equipmentSearchTokens(string $value): array
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', strtolower(trim($value))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || strlen($part) < 2) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Run existing Rentman equipment catalog sync into local rentman_equipments cache.
     */
    protected function triggerEquipmentCatalogSync(string $displayName): void
    {
        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
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
            Log::warning('Rentman Integration: Equipment sync failed during resolve/search', [
                'provider_company_id' => $this->providerCompanyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Link a Rentman equipment ID to a company_inventory row with company-scoped duplicate checks.
     *
     * @return array{success: bool, action: string, message: string, resource_id: string}
     */
    protected function attemptLinkRentmanEquipmentToEquipment(
        Equipment $equipment,
        string $rentmanEquipmentId,
        string $source,
    ): array {
        $resourceId = trim($rentmanEquipmentId);
        $companyId = (int) $equipment->company_id;

        if ($resourceId === '') {
            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => '',
                'message' => 'Rentman Equipment ID is empty; cannot link to company inventory.',
            ];
        }

        $isAlreadyLinked = (string) $equipment->rentman_equipment_id === $resourceId;

        if (!$isAlreadyLinked) {
            $duplicate = $this->findCompanyInventoryByRentmanEquipmentId(
                $companyId,
                $resourceId,
                (int) $equipment->id,
            );
            if ($duplicate) {
                $duplicate->loadMissing(['product.brand']);
                $result = $this->buildDuplicateRentmanEquipmentResponse($resourceId, $duplicate);

                Log::warning('Rentman Integration: Rentman Equipment ID already linked to another product in company', [
                    'provider_company_id' => $this->providerCompanyId,
                    'company_inventory_id' => $equipment->id,
                    'product_id' => $equipment->product_id,
                    'rentman_equipment_id' => $resourceId,
                    'linked_company_inventory_id' => $duplicate->id,
                    'linked_product_id' => $duplicate->product_id,
                ]);

                RentmanIntegrationDebugLog::step(
                    $this->rentalRequestId ?? 0,
                    $this->providerCompanyId,
                    'CONFIRM_RENTMAN_SYNC',
                    'DUPLICATE_RESOURCE',
                    [
                        'company_inventory_id' => $equipment->id,
                        'rentman_equipment_id' => $resourceId,
                        'linked_company_inventory_id' => $duplicate->id,
                    ],
                    'Do not overwrite existing company_inventory.rentman_equipment_id',
                );

                return $result;
            }
        }

        $action = $isAlreadyLinked
            ? 'already_linked'
            : ($source === 'create' ? 'created' : 'matched');

        return $this->finalizeRentmanEquipmentSync(
            $equipment,
            $resourceId,
            $action,
            assignResourceId: !$isAlreadyLinked,
            persistSource: $isAlreadyLinked ? null : $source,
        );
    }

    /**
     * Persist rentman_equipment_id on company_inventory and sync code/dimensions from Rentman (like FLEX).
     *
     * @return array{success: bool, action: string, message: string, resource_id: string}
     */
    protected function finalizeRentmanEquipmentSync(
        Equipment $equipment,
        string $resourceId,
        string $action,
        bool $assignResourceId,
        ?string $persistSource = null,
    ): array {
        $companyId = (int) $equipment->company_id;
        $displayName = $this->resolveMarketplaceProductDisplayName($equipment);

        // Ensure local cache stub exists, then refresh details from Rentman API (code + dimensions).
        $this->upsertLocalEquipmentCache($resourceId, $displayName, $equipment);

        try {
            $row = RentmanService::fetchAndStoreEquipmentDetails($companyId, $resourceId);
        } catch (\Throwable $e) {
            Log::error('Rentman Integration: Failed to retrieve Rentman equipment details for marketplace sync', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'rentman_equipment_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => $resourceId,
                'message' => 'Failed to retrieve Rentman equipment details: ' . $e->getMessage(),
            ];
        }

        try {
            DB::transaction(function () use (
                $equipment,
                $resourceId,
                $assignResourceId,
                $companyId,
                $row,
            ) {
                if ($assignResourceId) {
                    $locked = Equipment::query()->whereKey($equipment->id)->lockForUpdate()->first();
                    if (!$locked) {
                        throw new \RuntimeException('Company inventory record no longer exists.');
                    }

                    $duplicateInTransaction = Equipment::query()
                        ->where('company_id', $companyId)
                        ->where('rentman_equipment_id', $resourceId)
                        ->where('id', '!=', $locked->id)
                        ->lockForUpdate()
                        ->exists();

                    if ($duplicateInTransaction) {
                        throw new \RuntimeException('DUPLICATE_RESOURCE');
                    }

                    $locked->rentman_equipment_id = $resourceId;
                    $locked->save();

                    $equipment->rentman_equipment_id = $resourceId;
                }

                RentmanService::synchronizeMarketplaceInventoryFromRentmanRow(
                    $equipment,
                    $resourceId,
                    $row,
                );
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DUPLICATE_RESOURCE') {
                $duplicate = $this->findCompanyInventoryByRentmanEquipmentId(
                    $companyId,
                    $resourceId,
                    (int) $equipment->id,
                );
                if ($duplicate) {
                    $duplicate->loadMissing(['product.brand']);

                    return $this->buildDuplicateRentmanEquipmentResponse($resourceId, $duplicate);
                }
            }

            Log::error('Rentman Integration: Failed to synchronize Rentman equipment link', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'rentman_equipment_id' => $resourceId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => $resourceId,
                'message' => 'Failed to synchronize Rentman equipment.',
            ];
        } catch (\Throwable $e) {
            Log::error('Rentman Integration: Failed to synchronize Rentman equipment link', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'rentman_equipment_id' => $resourceId,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => $resourceId,
                'message' => 'Failed to synchronize Rentman equipment.',
            ];
        }

        // Soft-fail: keep PSM sync success even if Rentman custom field update fails.
        $this->ensurePsmCustomFieldsOnRentmanEquipment($resourceId, $equipment);

        if ($persistSource !== null) {
            $this->logPersistEquipmentId((int) $equipment->product_id, $resourceId, $persistSource);
        }

        $message = match ($action) {
            'already_linked' => "This product is already linked to Rentman with Equipment ID '{$resourceId}'.",
            'created' => 'Product was not found in Rentman. A new equipment was created and linked successfully.',
            default => 'Product found in Rentman and Equipment ID has been linked.',
        };

        Log::info('Rentman Integration: Marketplace sync completed', [
            'provider_company_id' => $this->providerCompanyId,
            'company_inventory_id' => $equipment->id,
            'product_id' => $equipment->product_id,
            'rentman_equipment_id' => $resourceId,
            'software_code' => $equipment->software_code,
            'action' => $action,
        ]);

        RentmanIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'CONFIRM_RENTMAN_SYNC',
            strtoupper($action),
            [
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'rentman_equipment_id' => $resourceId,
                'software_code' => $equipment->software_code,
            ],
            'Return synchronized Rentman Equipment ID',
        );

        return [
            'success' => true,
            'action' => $action,
            'message' => $message,
            'resource_id' => $resourceId,
        ];
    }

    /**
     * After successful PSM↔Rentman sync: ensure custom_3 (PSM Code) and custom_4 (published)=true.
     * Does not overwrite an existing non-empty custom_3. Soft-fails on API errors.
     */
    protected function ensurePsmCustomFieldsOnRentmanEquipment(
        string $equipmentId,
        Equipment $equipment,
    ): void {
        $equipment->loadMissing('product');
        $psmCode = trim((string) ($equipment->product?->psm_code ?? ''));

        $path = config('rentman.equipment_path', '/equipment') . '/' . ltrim($equipmentId, '/');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        try {
            $getResponse = $this->rentmanHttp(
                'GET',
                $url,
                null,
                RentmanIntegrationLog::ACTION_VALIDATE_EQUIPMENT,
                'Check custom_3/custom_4 then update if needed',
                null,
                $equipmentId,
            );

            if (!$getResponse->successful()) {
                Log::warning('Rentman Integration: Could not fetch equipment for custom field sync', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rentman_equipment_id' => $equipmentId,
                    'http_status' => $getResponse->status(),
                ]);

                return;
            }

            $data = self::extractDataObject($getResponse->json());
            $custom = isset($data['custom']) && is_array($data['custom']) ? $data['custom'] : [];
            $existingCustom3 = trim((string) ($custom['custom_3'] ?? ''));
            if (strtolower($existingCustom3) === 'null') {
                $existingCustom3 = '';
            }

            $needsCustom3 = $existingCustom3 === '' && $psmCode !== '';
            $custom4Truthy = filter_var($custom['custom_4'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $needsCustom4 = !$custom4Truthy;

            if (!$needsCustom3 && !$needsCustom4) {
                // Still force custom_4 true when already true — no PUT needed.
                return;
            }

            if ($needsCustom3) {
                $custom['custom_3'] = $psmCode;
            }
            $custom['custom_4'] = true;

            $payload = ['custom' => $custom];

            $putResponse = $this->rentmanHttp(
                'PUT',
                $url,
                $payload,
                RentmanIntegrationLog::ACTION_UPDATE_EQUIPMENT,
                'Continue after PSM custom field sync',
                null,
                $equipmentId,
            );

            $body = $putResponse->json();
            if (!$putResponse->successful()) {
                $this->logIntegration(
                    RentmanIntegrationLog::ACTION_UPDATE_EQUIPMENT,
                    RentmanIntegrationLog::STATUS_FAILED,
                    $url,
                    $payload,
                    is_array($body) ? $body : ['raw' => self::truncateHttpBody($putResponse->body())],
                    'HTTP ' . $putResponse->status(),
                    null,
                    $equipmentId,
                );
                Log::warning('Rentman Integration: Failed to update PSM custom fields on equipment', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rentman_equipment_id' => $equipmentId,
                    'http_status' => $putResponse->status(),
                ]);

                return;
            }

            $this->logIntegration(
                RentmanIntegrationLog::ACTION_UPDATE_EQUIPMENT,
                RentmanIntegrationLog::STATUS_SUCCESS,
                $url,
                $payload,
                is_array($body) ? $body : null,
                null,
                null,
                $equipmentId,
            );

            Log::info('Rentman Integration: PSM custom fields updated on equipment', [
                'provider_company_id' => $this->providerCompanyId,
                'rentman_equipment_id' => $equipmentId,
                'custom_3_set' => $needsCustom3,
                'custom_4' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Rentman Integration: Soft-fail ensuring PSM custom fields on equipment', [
                'provider_company_id' => $this->providerCompanyId,
                'rentman_equipment_id' => $equipmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function findCompanyInventoryByRentmanEquipmentId(
        int $companyId,
        string $rentmanEquipmentId,
        ?int $excludeInventoryId = null,
    ): ?Equipment {
        $query = Equipment::query()
            ->where('company_id', $companyId)
            ->where('rentman_equipment_id', trim($rentmanEquipmentId));

        if ($excludeInventoryId !== null) {
            $query->where('id', '!=', $excludeInventoryId);
        }

        return $query->first();
    }

    /**
     * @return array{success: false, action: 'duplicate_resource', message: string, resource_id: string}
     */
    protected function buildDuplicateRentmanEquipmentResponse(
        string $resourceId,
        Equipment $linkedEquipment,
    ): array {
        $linkedEquipment->loadMissing(['product.brand']);
        $linkedProductName = $linkedEquipment->product
            ? FlexIntegrationService::productDisplayName($linkedEquipment->product)
            : 'another product';

        return [
            'success' => false,
            'action' => 'duplicate_resource',
            'resource_id' => $resourceId,
            'message' => "The Rentman Equipment ID '{$resourceId}' is already linked to the product '{$linkedProductName}'.",
        ];
    }

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

    /**
     * Search Rentman contacts by requester company name (exact, case-insensitive).
     */
    protected function findContactByCompanyName(string $companyName): ?string
    {
        $path = config('rentman.contact_list_path', '/contacts');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $limit = (int) config('rentman.contact_list_limit', 100);
        $maxPages = (int) config('rentman.contact_list_max_pages', 50);
        $offset = 0;
        $companyNameLower = strtolower(trim($companyName));

        if ($companyNameLower === '') {
            return null;
        }

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
                    'Match contact by company name or create contact',
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
                $matched = $this->contactMatchesCompanyName($item, $companyNameLower);
                if ($matched !== null) {
                    $this->logIntegration(
                        RentmanIntegrationLog::ACTION_SEARCH_CONTACT,
                        RentmanIntegrationLog::STATUS_SUCCESS,
                        $url,
                        ['matched_id' => $matched, 'company_name' => $companyName],
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
            ['company_name' => $companyName],
            ['matched' => false],
        );

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function contactMatchesCompanyName(array $item, string $companyNameLower): ?string
    {
        $id = $item['id'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }

        $candidates = [
            strtolower(trim((string) ($item['name'] ?? ''))),
            strtolower(trim((string) ($item['displayname'] ?? ''))),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $candidate === $companyNameLower) {
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

        $matches = $this->collectLocalRentmanEquipmentMatches($trimmed);

        return $matches[0]['resource_id'] ?? null;
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

    /**
     * Create Rentman equipment and persist the mapping (rental-request resolve path).
     */
    protected function createAndLinkRentmanEquipmentForResolve(
        string $displayName,
        int $productId,
        ?Equipment $inventory,
        string $source = 'create',
    ): ?string {
        $rentalRequestId = $this->rentalRequestId ?? 0;

        try {
            $createdId = $this->createRentmanEquipment($displayName, $inventory);
            self::persistRentmanEquipmentOnInventory($this->providerCompanyId, $productId, $createdId);
            $this->upsertLocalEquipmentCache($createdId, $displayName, $inventory);
            $this->logPersistEquipmentId($productId, $createdId, $source);

            RentmanIntegrationDebugLog::step(
                $rentalRequestId,
                $this->providerCompanyId,
                'EQUIPMENT_RESOLVE',
                'CREATED',
                [
                    'product_id' => $productId,
                    'rentman_equipment_id' => $createdId,
                    'name' => $displayName,
                    'source' => $source,
                ],
                'Attach newly created equipment to project request',
            );

            return $createdId;
        } catch (\Throwable $e) {
            Log::error('Rentman Integration: Equipment create failed', [
                'provider_company_id' => $this->providerCompanyId,
                'product_id' => $productId,
                'name' => $displayName,
                'source' => $source,
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
                    'source' => $source,
                    'error' => $e->getMessage(),
                ],
                'Mark product missing; continue with next line',
            );

            return null;
        }
    }

    /**
     * Push latest PSM inventory values onto an existing Rentman equipment (rental-request single match).
     * Reuses the same field mapping as equipment create. Soft-fails so the request can still attach.
     */
    protected function syncPsmInventoryDetailsToRentmanEquipment(
        string $equipmentId,
        string $displayName,
        ?Equipment $inventory,
    ): void {
        if ($inventory === null) {
            return;
        }

        $path = config('rentman.equipment_path', '/equipment') . '/' . ltrim($equipmentId, '/');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $payload = $this->buildRentmanEquipmentPayloadFromInventory($displayName, $inventory);
        unset($payload['type'], $payload['rental_sales'], $payload['is_physical']);

        try {
            $getResponse = $this->rentmanHttp(
                'GET',
                $url,
                null,
                RentmanIntegrationLog::ACTION_VALIDATE_EQUIPMENT,
                'Merge existing custom fields then PUT PSM inventory details',
                null,
                $equipmentId,
            );

            if ($getResponse->successful()) {
                $data = self::extractDataObject($getResponse->json());
                $existingCustom = isset($data['custom']) && is_array($data['custom']) ? $data['custom'] : [];
                $payload['custom'] = array_merge($existingCustom, $payload['custom'] ?? []);
            }

            $putResponse = $this->rentmanHttp(
                'PUT',
                $url,
                $payload,
                RentmanIntegrationLog::ACTION_UPDATE_EQUIPMENT,
                'Use synced Rentman equipment on the project request',
                null,
                $equipmentId,
            );

            $body = $putResponse->json();
            if (!$putResponse->successful()) {
                $this->logIntegration(
                    RentmanIntegrationLog::ACTION_UPDATE_EQUIPMENT,
                    RentmanIntegrationLog::STATUS_FAILED,
                    $url,
                    $payload,
                    is_array($body) ? $body : ['raw' => self::truncateHttpBody($putResponse->body())],
                    'HTTP ' . $putResponse->status(),
                    null,
                    $equipmentId,
                );
                Log::warning('Rentman Integration: Failed to sync PSM details onto matched Rentman equipment', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rentman_equipment_id' => $equipmentId,
                    'http_status' => $putResponse->status(),
                ]);

                return;
            }

            $this->logIntegration(
                RentmanIntegrationLog::ACTION_UPDATE_EQUIPMENT,
                RentmanIntegrationLog::STATUS_SUCCESS,
                $url,
                $payload,
                is_array($body) ? $body : null,
                null,
                null,
                $equipmentId,
            );

            Log::info('Rentman Integration: PSM inventory details synced onto matched Rentman equipment', [
                'provider_company_id' => $this->providerCompanyId,
                'rentman_equipment_id' => $equipmentId,
                'company_inventory_id' => $inventory->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Rentman Integration: Soft-fail syncing PSM details onto matched Rentman equipment', [
                'provider_company_id' => $this->providerCompanyId,
                'rentman_equipment_id' => $equipmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PSM → Rentman equipment fields already supported by create/sync.
     *
     * @return array<string, mixed>
     */
    protected function buildRentmanEquipmentPayloadFromInventory(string $displayName, ?Equipment $inventory): array
    {
        $payload = [
            'name' => $displayName,
            'type' => 'item',
            'rental_sales' => 'Rental',
            'is_physical' => 'Physical equipment',
            // Mirrors FLEX "PSM Code" + "Publish to PSM" custom fields on inventory create.
            'custom' => [
                'custom_4' => true,
            ],
        ];

        if ($inventory === null) {
            return $payload;
        }

        $inventory->loadMissing('product');
        $psmCode = trim((string) ($inventory->product?->psm_code ?? ''));
        if ($psmCode !== '') {
            $payload['custom']['custom_3'] = $psmCode;
        }

        $softwareCode = trim((string) ($inventory->software_code ?? ''));
        if ($softwareCode !== '') {
            $payload['code'] = $softwareCode;
        }

        $qty = (int) ($inventory->quantity ?? 0);
        if ($qty > 0) {
            $payload['unit'] = (string) $qty;
        }
        if ($inventory->rental_price !== null) {
            $payload['price'] = (float) $inventory->rental_price;
            $payload['subrental_costs'] = (float) $inventory->rental_price;
        }
        foreach (['height', 'width', 'length', 'weight'] as $dim) {
            if ($inventory->{$dim} !== null) {
                $payload[$dim] = (float) $inventory->{$dim};
            }
        }
        $coo = self::resolveRentmanCountryOfOriginCode($inventory->country_of_origin ?? null);
        if ($coo !== null) {
            $payload['country_of_origin'] = $coo;
        }

        return $payload;
    }

    protected function createRentmanEquipment(string $displayName, ?Equipment $inventory): string
    {
        $path = config('rentman.equipment_path', '/equipment');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $payload = $this->buildRentmanEquipmentPayloadFromInventory($displayName, $inventory);

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
     * POST /contacts — company contact payload (person is added via contactpersons next).
     *
     * @return array<string, mixed>
     */
    protected static function buildRentmanContactCreatePayload(User $requester, ?UserProfile $profile): array
    {
        $email = trim((string) ($profile->email ?? $requester->email ?? ''));
        $mobile = trim((string) ($profile->mobile ?? ''));

        $company = $requester->company;
        if ($company !== null) {
            $company->loadMissing(['country', 'state', 'city']);
        }

        $companyName = trim((string) ($company?->name ?? ''));
        if ($companyName === '') {
            $companyName = self::resolveRequesterDisplayName($requester, $profile);
        }
        if ($companyName === '') {
            $companyName = 'Customer';
        }

        $payload = [
            'type' => config('rentman.contact_type', 'company'),
            'folder' => config('rentman.contact_folder', '/folders/0'),
            'name' => $companyName,
            'visit_district' => '',
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
            $parsed = self::splitStreetAndHouseNumber($line1);

            if ($parsed['street'] !== '') {
                $payload['visit_street'] = $parsed['street'];
            }
            $visitNumber = $parsed['number'] !== '' ? $parsed['number'] : $line2;
            if ($visitNumber !== '') {
                $payload['visit_number'] = $visitNumber;
            }
            $city = trim((string) ($company->city?->name ?? ''));
            if ($city !== '') {
                $payload['visit_city'] = $city;
            }
            $stateCode = trim((string) ($company->state?->code ?? ''));
            $stateName = trim((string) ($company->state?->name ?? ''));
            $state = $stateCode !== '' ? $stateCode : $stateName;
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

    /**
     * Split a single address line into street + house number.
     * Supports "123 Main Street" and "Main Street 123".
     *
     * @return array{street: string, number: string}
     */
    protected static function splitStreetAndHouseNumber(string $addressLine): array
    {
        $line = trim(preg_replace('/\s+/', ' ', $addressLine) ?? '');
        if ($line === '') {
            return ['street' => '', 'number' => ''];
        }

        // Leading house number: "123 Main Street", "123A Main Street"
        if (preg_match('/^(\d+[a-zA-Z]?)\s+(.+)$/u', $line, $m)) {
            return [
                'number' => trim($m[1]),
                'street' => trim($m[2]),
            ];
        }

        // Trailing house number: "Main Street 123", "Main Street, 123"
        if (preg_match('/^(.+?)[,\s]+(\d+[a-zA-Z]?)$/u', $line, $m)) {
            return [
                'number' => trim($m[2]),
                'street' => trim($m[1], " \t,"),
            ];
        }

        return ['street' => $line, 'number' => ''];
    }

    /**
     * POST /contacts/{id}/contactpersons — requester person details.
     *
     * @return array<string, mixed>
     */
    protected static function buildRentmanContactPersonCreatePayload(
        User $requester,
        ?UserProfile $profile,
    ): array {
        $firstName = trim((string) ($profile->first_name ?? ''));
        $lastName = trim((string) ($profile->last_name ?? ''));
        $email = trim((string) ($profile->email ?? $requester->email ?? ''));
        $mobile = trim((string) ($profile->mobile ?? ''));

        $company = $requester->company;
        if ($company !== null) {
            $company->loadMissing(['country', 'state', 'city']);
        }

        $payload = [
            'firstname' => $firstName !== '' ? $firstName : ($requester->username ?? 'Customer'),
            'lastname' => $lastName !== '' ? $lastName : '—',
        ];

        if ($mobile !== '') {
            $payload['mobilephone'] = $mobile;
        }
        if ($email !== '') {
            $payload['email'] = $email;
        }

        if ($company !== null) {
            $line1 = trim((string) ($company->address_line_1 ?? ''));
            $line2 = trim((string) ($company->address_line_2 ?? ''));
            $parsed = self::splitStreetAndHouseNumber($line1);

            if ($parsed['street'] !== '') {
                $payload['street'] = $parsed['street'];
            }
            $number = $parsed['number'] !== '' ? $parsed['number'] : $line2;
            if ($number !== '') {
                $payload['number'] = $number;
            }
            $postal = trim((string) ($company->postal_code ?? ''));
            if ($postal !== '') {
                $payload['postalcode'] = $postal;
            }
            $city = trim((string) ($company->city?->name ?? ''));
            if ($city !== '') {
                $payload['city'] = $city;
            }
            $stateCode = trim((string) ($company->state?->code ?? ''));
            $stateName = trim((string) ($company->state?->name ?? ''));
            $state = $stateCode !== '' ? $stateCode : $stateName;
            if ($state !== '') {
                $payload['state'] = $state;
            }
            $countryCode = strtolower(trim((string) ($company->country?->iso_code ?? '')));
            if ($countryCode !== '') {
                $payload['country'] = $countryCode;
            }
        }

        return $payload;
    }

    /**
     * Rentman country_of_origin must be ISO 3166-1 alpha-2 lowercase (e.g. usa → us).
     */
    protected static function resolveRentmanCountryOfOriginCode(mixed $value): ?string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }

        if (strlen($raw) === 2 && ctype_alpha($raw)) {
            return $raw;
        }

        $aliases = [
            'usa' => 'us',
            'u.s.a.' => 'us',
            'u.s.' => 'us',
            'united states' => 'us',
            'united states of america' => 'us',
            'uk' => 'gb',
            'great britain' => 'gb',
            'united kingdom' => 'gb',
        ];
        if (isset($aliases[$raw])) {
            return $aliases[$raw];
        }

        $country = Country::query()
            ->where(function ($q) use ($raw) {
                $q->whereRaw('LOWER(iso_code) = ?', [$raw])
                    ->orWhereRaw('LOWER(name) = ?', [$raw])
                    ->orWhereRaw('LOWER(normalized_name) = ?', [$raw]);
            })
            ->first();

        $iso = strtolower(trim((string) ($country?->iso_code ?? '')));
        if (strlen($iso) === 2 && ctype_alpha($iso)) {
            return $iso;
        }

        Log::warning('Rentman Integration: Could not map country_of_origin to 2-letter code; omitting field', [
            'country_of_origin' => $raw,
        ]);

        return null;
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
