<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Country;
use App\Models\Equipment;
use App\Models\FlexIntegrationLog;
use App\Models\Product;
use App\Models\RentalJob;
use App\Models\RentalJobComment;
use App\Models\SupplyJob;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\FlexIntegrationDebugLog;
use App\Support\ProductNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FlexIntegrationService
{
    public const SYNC_PENDING = 'PENDING';

    public const SYNC_PROCESSING = 'PROCESSING';

    public const SYNC_COMPLETED = 'COMPLETED';

    public const SYNC_FAILED = 'FAILED';

    public const SYNC_PARTIAL = 'PARTIAL';

    protected int $timeout = 45;

    protected ?FlexIntegrationLogger $flexLogger = null;

    protected ?int $rentalRequestId = null;

    public function __construct(
        protected int $providerCompanyId,
        protected CompanyIntegration $integration,
        protected string $baseUrl,
        protected string $apiKey,
    ) {}

    public function setFlexLogger(?FlexIntegrationLogger $logger): self
    {
        $this->flexLogger = $logger;

        return $this;
    }

    public function setRentalRequestId(?int $rentalRequestId): self
    {
        $this->rentalRequestId = $rentalRequestId;

        return $this;
    }

    /**
     * Central Flex HTTP client: logs URL, payload, response, and next_step for every call.
     *
     * @param  array<string, mixed>|null  $payload  Query params for GET, JSON body for POST/PUT/PATCH
     * @param  string  $bodyMode  'json' (default) | 'query' (POST/PUT/PATCH params as query string, empty body — matches Flex Swagger add-resource)
     */
    protected function flexHttp(
        string $method,
        string $url,
        ?array $payload,
        string $action,
        string $nextStep,
        ?string $flexQuoteId = null,
        ?string $flexProductId = null,
        string $bodyMode = 'json',
    ): \Illuminate\Http\Client\Response {
        $method = strtoupper($method);
        $rentalRequestId = $this->rentalRequestId ?? 0;
        $requestUrl = $url;

        if ($bodyMode === 'query' && $payload !== null && $payload !== [] && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $requestUrl .= (str_contains($url, '?') ? '&' : '?') . http_build_query($payload);
        }

        FlexIntegrationDebugLog::step(
            $rentalRequestId,
            $this->providerCompanyId,
            $action,
            'REQUEST',
            [
                'http_method' => $method,
                'api_url' => $requestUrl,
                'body_mode' => $bodyMode,
                'request_payload' => $payload,
            ],
            $nextStep,
        );

        $this->logIntegration(
            $action,
            FlexIntegrationLog::STATUS_PROCESSING,
            $requestUrl,
            $payload,
            null,
            $method . ' outbound — next: ' . $nextStep,
            $flexQuoteId,
            $flexProductId,
        );

        try {
            $headers = $this->authHeaders();
            if ($bodyMode === 'json') {
                $headers['Content-Type'] = 'application/json';
            }

            $pending = Http::timeout($this->timeout)->withHeaders($headers);

            $response = match ($method) {
                'GET' => $pending->get($requestUrl, $bodyMode === 'query' ? [] : ($payload ?? [])),
                'POST' => $bodyMode === 'query'
                    ? $pending->withBody('', 'text/plain')->post($requestUrl)
                    : $pending->post($requestUrl, $payload ?? []),
                'PUT' => $bodyMode === 'query'
                    ? $pending->withBody('', 'text/plain')->put($requestUrl)
                    : $pending->put($requestUrl, $payload ?? []),
                'PATCH' => $bodyMode === 'query'
                    ? $pending->withBody('', 'text/plain')->patch($requestUrl)
                    : $pending->patch($requestUrl, $payload ?? []),
                'DELETE' => $pending->delete($requestUrl, $bodyMode === 'query' ? [] : ($payload ?? [])),
                default => throw new \InvalidArgumentException('Unsupported Flex HTTP method: ' . $method),
            };
        } catch (\Throwable $e) {
            FlexIntegrationDebugLog::apiCall(
                $rentalRequestId,
                $this->providerCompanyId,
                $action,
                $method,
                $requestUrl,
                $payload,
                null,
                null,
                false,
                $nextStep,
                $e->getMessage(),
            );

            $this->logIntegration(
                FlexIntegrationLog::ACTION_API_ERROR,
                FlexIntegrationLog::STATUS_FAILED,
                $requestUrl,
                $payload,
                null,
                $e->getMessage(),
                $flexQuoteId,
                $flexProductId,
            );

            throw $e;
        }

        $json = $response->json();
        $bodyForLog = is_array($json) ? $json : ['raw' => self::truncateHttpBody($response->body())];
        $success = $response->successful();

        FlexIntegrationDebugLog::apiCall(
            $rentalRequestId,
            $this->providerCompanyId,
            $action,
            $method,
            $requestUrl,
            $payload,
            $response->status(),
            $bodyForLog,
            $success,
            $nextStep,
            $success ? null : ('HTTP ' . $response->status()),
        );

        return $response;
    }

    public static function forProviderCompany(int $providerCompanyId): ?self
    {
        $integration = CompanyIntegration::where('company_id', $providerCompanyId)
            ->where('integration_type', 'flex')
            ->first();

        if (!$integration || !$integration->isConnected()) {
            return null;
        }

        $baseUrl = rtrim((string) $integration->api_base_url, '/');
        $apiKey = (string) $integration->api_key;

        return new self($providerCompanyId, $integration, $baseUrl, $apiKey);
    }

    public function getBaseUrlForLogging(): string
    {
        return $this->baseUrl;
    }

    protected function logIntegration(
        string $action,
        string $status,
        ?string $requestUrl = null,
        mixed $requestPayload = null,
        mixed $responsePayload = null,
        ?string $errorMessage = null,
        ?string $flexQuoteId = null,
        ?string $flexProductId = null,
    ): void {
        if ($this->flexLogger === null) {
            return;
        }

        $this->flexLogger->log($action, $status, $requestUrl, $requestPayload, $responsePayload, $errorMessage, $flexQuoteId, $flexProductId);
    }

    /**
     * Many Flex endpoints return a bare JSON array [{...}, ...] or wrap rows in content/data/results.
     */
    protected static function normalizeFlexItemArray(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        if ($decoded !== [] && array_is_list($decoded)) {
            return $decoded;
        }

        foreach (['content', 'data', 'results'] as $key) {
            if (empty($decoded[$key]) || !is_array($decoded[$key])) {
                continue;
            }
            $inner = $decoded[$key];
            if ($inner !== [] && array_is_list($inner)) {
                return $inner;
            }
            if (is_array($inner) && (isset($inner['id']) || isset($inner['name']))) {
                return [$inner];
            }
        }

        return [];
    }

    /**
     * Flex expects datetimes like 2026-04-20T05:00:00, not plain dates (Y-m-d) — otherwise
     * "Text '2026-04-14' could not be parsed at index 10" (parser expects "T" and time after the date).
     */
    protected static function formatFlexQuotePlannedDateTime(mixed $date, bool $endOfDay): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $c = Carbon::parse($date)->timezone(config('app.timezone', 'UTC'));
        $c = $endOfDay ? $c->copy()->endOfDay() : $c->copy()->startOfDay();
        $format = config('flex.quote_planned_datetime_format', 'Y-m-d\TH:i:s');

        return $c->format($format);
    }

    /**
     * Parse GET /f5/api/element/{definitionId}/fields response for default IDs used on new quotes.
     */
    protected static function parseQuoteDefaultsFromElementFieldsResponse(array $json): array
    {
        $defaults = [];
        if (!empty($json['locationId'])) {
            $defaults['location_id'] = (string) $json['locationId'];
        }

        foreach ($json['fields'] ?? [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $fid = (string) ($field['id'] ?? '');
            $data = $field['dataPoint']['data'] ?? null;
            if (!is_array($data)) {
                continue;
            }
            $rowId = $data['id'] ?? null;
            if ($rowId === null || $rowId === '') {
                continue;
            }
            $idStr = (string) $rowId;

            match ($fid) {
                'statusId' => $defaults['status_id'] = $idStr,
                'personResponsibleId' => $defaults['person_responsible_id'] = $idStr,
                'defaultPricingModelId' => $defaults['default_pricing_model_id'] = $idStr,
                'locationId' => $defaults['location_id'] = $idStr,
                default => null,
            };
        }

        return $defaults;
    }

    /**
     * GET element definition fields (cached). Non-empty env values always override these.
     */
    protected function fetchQuoteDefaultsFromElementFields(): array
    {
        if (!config('flex.use_element_fields_api', true)) {
            return [];
        }

        try {
            $definitionId = $this->getSalesQuoteDefinitionId();
        } catch (\Throwable $e) {
            Log::warning('Flex Integration: cannot fetch element fields — Quote definitionId unresolved', [
                'provider_company_id' => $this->providerCompanyId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $cacheKey = 'flex_quote_elem_fields_' . $this->providerCompanyId . '_' . $definitionId;
        $ttl = (int) config('flex.element_fields_cache_ttl', 3600);

        return Cache::remember($cacheKey, $ttl, function () use ($definitionId) {
            $pattern = config('flex.element_fields_path_pattern', '/f5/api/element/%s/fields');
            $path = sprintf($pattern, $definitionId);
            $url = $this->baseUrl . '/' . ltrim($path, '/');

            try {
                $response = $this->flexHttp(
                    'GET',
                    $url,
                    [
                        'elementId' => '',
                        'parentElementId' => '',
                    ],
                    FlexIntegrationLog::ACTION_FETCH_ELEMENT_FIELDS,
                    'Use resolved quote field defaults (statusId, locationId, etc.) when creating sales quote',
                );

                $json = $response->json();

                if (!$response->successful()) {
                    $this->logIntegration(
                        FlexIntegrationLog::ACTION_FETCH_ELEMENT_FIELDS,
                        FlexIntegrationLog::STATUS_FAILED,
                        $url,
                        ['definitionId' => $definitionId, 'query' => ['elementId' => '', 'parentElementId' => '']],
                        is_array($json) ? $json : ['raw' => self::truncateHttpBody($response->body())],
                        'HTTP ' . $response->status(),
                    );

                    return [];
                }

                $defaults = self::parseQuoteDefaultsFromElementFieldsResponse(is_array($json) ? $json : []);

                $this->logIntegration(
                    FlexIntegrationLog::ACTION_FETCH_ELEMENT_FIELDS,
                    FlexIntegrationLog::STATUS_SUCCESS,
                    $url,
                    ['definitionId' => $definitionId],
                    ['resolved_defaults' => $defaults],
                    null,
                );

                return $defaults;
            } catch (\Throwable $e) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_FETCH_ELEMENT_FIELDS,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    ['definitionId' => $definitionId],
                    null,
                    $e->getMessage(),
                );

                return [];
            }
        });
    }

    /**
     * @return array{status_id: ?string, person_responsible_id: ?string, default_pricing_model_id: ?string, location_id: ?string}
     */
    protected function resolveQuoteFieldIds(): array
    {
        $settings = $this->quoteSettings();
        $api = $this->fetchQuoteDefaultsFromElementFields();

        return [
            'status_id' => !empty($settings['status_id']) ? (string) $settings['status_id'] : ($api['status_id'] ?? null),
            'person_responsible_id' => !empty($settings['person_responsible_id']) ? (string) $settings['person_responsible_id'] : ($api['person_responsible_id'] ?? null),
            'default_pricing_model_id' => !empty($settings['default_pricing_model_id']) ? (string) $settings['default_pricing_model_id'] : ($api['default_pricing_model_id'] ?? null),
            'location_id' => !empty($settings['location_id']) ? (string) $settings['location_id'] : ($api['location_id'] ?? null),
        ];
    }

    public static function checkCompanyIntegration(int $providerCompanyId): bool
    {
        $company = Company::with('rentalSoftware')->find($providerCompanyId);
        if (!$company) {
            Log::info('Flex integration: provider company not found.', ['provider_company_id' => $providerCompanyId]);

            return false;
        }

        $softwareName = strtolower(trim((string) ($company->rentalSoftware->name ?? '')));
        if ($softwareName === '' || !str_contains($softwareName, 'flex')) {
            Log::info('Provider does not have Flex integration. Skipping Flex Sales Quote creation.', [
                'provider_company_id' => $providerCompanyId,
                'rental_software' => $company->rentalSoftware->name ?? null,
            ]);

            return false;
        }

        if (!CompanyIntegration::where('company_id', $providerCompanyId)
            ->where('integration_type', 'flex')
            ->exists()) {
            Log::info('Provider does not have Flex integration. Skipping Flex Sales Quote creation.', [
                'provider_company_id' => $providerCompanyId,
                'reason' => 'no_flex_company_integration',
            ]);

            return false;
        }

        return true;
    }

    public function getProSubrentalReferralSourceId(): ?string
    {
        $cacheKey = 'flex_referral_source_pro_subrental_' . $this->providerCompanyId;
        $ttl = (int) config('flex.referral_source_cache_ttl', 86400);
        $cached = Cache::get($cacheKey);
        if ($cached !== null && $cached !== '') {
            return (string) $cached;
        }

        $id = $this->fetchProSubrentalReferralSourceIdFromApi();
        if ($id !== null) {
            Cache::put($cacheKey, $id, $ttl);
        }

        return $id;
    }

    protected function fetchProSubrentalReferralSourceIdFromApi(): ?string
    {
        $path = config('flex.referral_source_path', '/f5/api/referral-source/identity');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $expectedName = 'Pro Subrental Marketplace';

        try {
            $response = $this->flexHttp(
                'GET',
                $url,
                null,
                FlexIntegrationLog::ACTION_FETCH_REFERRAL_SOURCE,
                'Attach referralSourceId to sales quote create payload',
            );

            $body = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_FETCH_REFERRAL_SOURCE,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    ['expected_name' => $expectedName],
                    is_array($body) ? $body : ['raw' => $response->body()],
                    'HTTP ' . $response->status(),
                );

                return null;
            }

            $items = self::normalizeFlexItemArray($body);
            if ($items === []) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_FETCH_REFERRAL_SOURCE,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    ['expected_name' => $expectedName],
                    is_array($body) ? $body : null,
                    'Invalid or empty response — expected a JSON array of {id,name} or {content:[...]}',
                );

                return null;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                if (strcasecmp($name, $expectedName) === 0) {
                    $id = $item['id'] ?? null;
                    if ($id !== null && $id !== '') {
                        $idStr = (string) $id;
                        $this->logIntegration(
                            FlexIntegrationLog::ACTION_FETCH_REFERRAL_SOURCE,
                            FlexIntegrationLog::STATUS_SUCCESS,
                            $url,
                            ['expected_name' => $expectedName],
                            ['id' => $idStr, 'name' => $name],
                        );

                        return $idStr;
                    }
                }
            }

            $parsedNames = [];
            foreach ($items as $item) {
                if (is_array($item) && isset($item['name'])) {
                    $parsedNames[] = trim((string) $item['name']);
                }
            }

            $this->logIntegration(
                FlexIntegrationLog::ACTION_FETCH_REFERRAL_SOURCE,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                ['expected_name' => $expectedName],
                [
                    'parsed_referral_names_from_api' => $parsedNames,
                    'items_count' => count($items),
                    'response_shape' => is_array($body) ? (array_is_list($body) ? 'json_array' : 'json_object') : 'not_array',
                    'hint' => 'Match name "Pro Subrental Marketplace" (case-insensitive) to one row\'s id.',
                ],
                'Referral source not found — see parsed_referral_names_from_api in flex_integration_logs.response_payload',
            );

            return null;
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_API_ERROR,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                ['expected_name' => $expectedName],
                null,
                $e->getMessage(),
            );

            return null;
        }
    }

    public function getOrCreateClient(User $requester): string
    {
        $profile = $requester->profile ?? UserProfile::where('user_id', $requester->id)->first();
        $requesterName = self::resolveRequesterDisplayName($requester, $profile);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            FlexIntegrationLog::ACTION_CREATE_CLIENT,
            'STARTED',
            ['name' => $requesterName !== '' ? $requesterName : null],
            'Search Flex contact by requester name only; create contact if not found',
        );

        if ($requesterName !== '') {
            $found = $this->searchContact($requesterName);
            if ($found !== null) {
                Log::info('Flex Integration: Client Found', [
                    'provider_company_id' => $this->providerCompanyId,
                    'client_id' => $found,
                    'matched_by' => 'name',
                ]);

                FlexIntegrationDebugLog::step(
                    $this->rentalRequestId ?? 0,
                    $this->providerCompanyId,
                    FlexIntegrationLog::ACTION_CREATE_CLIENT,
                    'SUCCESS',
                    ['flex_client_id' => $found, 'matched_by' => 'name'],
                    'Create Flex sales quote using this clientId',
                );

                return $found;
            }
        }

        $clientResourceType = $this->getClientResourceType();
        if (!$clientResourceType) {
            throw new \RuntimeException('Flex Client resource type id not found (GET resource-type/nodes?classname=resource-type&nodeId=root, name = Client).');
        }

        $requester->loadMissing(['company.country', 'company.state', 'company.city']);

        $payload = self::buildFlexContactCreatePayload($requester, $profile, $clientResourceType);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            FlexIntegrationLog::ACTION_CREATE_CLIENT,
            'CREATE_REQUEST',
            [
                'company_id' => $requester->company?->id,
                'has_address' => ($payload['addresses'] ?? []) !== [],
                'has_phone' => $payload['mobilePhone'] !== null,
                'has_email' => ($payload['email'] ?? '') !== '',
                'address_city' => $requester->company?->city?->name,
                'address_state' => $requester->company?->state?->name,
                'address_country_code' => strtoupper(trim((string) ($requester->company?->country?->iso_code ?? ''))),
                'address_postal_code' => trim((string) ($requester->company?->postal_code ?? '')),
                'address_payload' => $payload['addresses'][0] ?? null,
            ],
            'POST Flex Create Contact API',
        );

        $path = config('flex.contact_create_path', '/f5/api/contact');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $response = $this->flexHttp(
            'POST',
            $url,
            $payload,
            FlexIntegrationLog::ACTION_CREATE_CLIENT,
            'Create Flex sales quote with resolved clientId',
        );

        $body = $response->json();

        if (!$response->successful()) {
            $respLog = is_array($body) ? $body : [];
            $respLog['http_status'] = $response->status();
            $respLog['raw_body_preview'] = self::truncateHttpBody($response->body());

            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_CLIENT,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                $respLog,
                'HTTP ' . $response->status() . ' — see raw_body_preview in flex_integration_logs',
            );

            throw new \RuntimeException(
                'Flex contact create failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $id = $body['id'] ?? ($body['content']['id'] ?? null) ?? ($body['referenceData']['id'] ?? null);
        if (!$id) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_CLIENT,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($body) ? $body : null,
                'Missing id in response',
            );

            throw new \RuntimeException('Flex contact create: missing id in response.');
        }

        $this->logIntegration(
            FlexIntegrationLog::ACTION_CREATE_CLIENT,
            FlexIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($body) ? $body : null,
            null,
            null,
            null,
        );

        Log::info('Flex Integration: Client Created', [
            'provider_company_id' => $this->providerCompanyId,
            'client_id' => (string) $id,
        ]);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            FlexIntegrationLog::ACTION_CREATE_CLIENT,
            'SUCCESS',
            ['flex_client_id' => (string) $id, 'matched_by' => 'created'],
            'Create Flex sales quote using this clientId',
        );

        return (string) $id;
    }

    public function createSalesQuote(RentalJob $rentalJob, string $clientId): array
    {
        $definitionId = $this->getSalesQuoteDefinitionId();
        $settings = $this->quoteSettings();

        $referralSourceId = $this->getProSubrentalReferralSourceId();
        if (!$referralSourceId) {
            throw new \RuntimeException('Flex referral source "Pro Subrental Marketplace" could not be resolved.');
        }

        $start = self::formatFlexQuotePlannedDateTime($rentalJob->from_date, false);
        $end = self::formatFlexQuotePlannedDateTime($rentalJob->to_date, true);
        if ($start === null || $end === null) {
            throw new \RuntimeException('Rental job from_date and to_date are required for Flex plannedStartDate / plannedEndDate.');
        }

        $resolved = $this->resolveQuoteFieldIds();

        $payload = [
            'definitionId' => $definitionId,
            'name' => $rentalJob->name,
            'plannedStartDate' => $start,
            'plannedEndDate' => $end,
            'clientId' => $clientId,
            'referralSourceId' => $referralSourceId,
        ];

        $optionalQuoteFields = array_filter([
            'statusId' => $resolved['status_id'],
            'personResponsibleId' => $resolved['person_responsible_id'],
            'defaultPricingModelId' => $resolved['default_pricing_model_id'],
            'locationId' => $resolved['location_id'],
        ], static fn ($v) => $v !== null && $v !== '');

        $payload = array_merge($payload, $optionalQuoteFields);

        if (config('flex.include_currency_in_quote', false)) {
            $currencyId = $settings['currency_id'] ?: FlexService::getUsdCurrencyId($this->providerCompanyId);
            if ($currencyId) {
                $payload['currencyId'] = $currencyId;
            }
        }

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'CREATE_QUOTE',
            'PAYLOAD',
            [
                'definitionId' => $definitionId,
                'clientId' => $clientId,
                'referralSourceId' => $referralSourceId,
                'name' => $payload['name'] ?? null,
            ],
            'POST /f5/api/element/ to create sales quote',
        );

        $path = config('flex.element_create_path', '/f5/api/element');
        $url = rtrim($this->baseUrl . '/' . ltrim($path, '/'), '/') . '/';

        $response = $this->flexHttp(
            'POST',
            $url,
            $payload,
            FlexIntegrationLog::ACTION_CREATE_QUOTE,
            'Attach inventory line items to the new sales quote',
        );

        $data = $response->json();

        if (!$response->successful()) {
            $responseForLog = is_array($data) ? $data : [];
            $responseForLog['http_status'] = $response->status();
            $responseForLog['raw_body_preview'] = self::truncateHttpBody($response->body());

            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_QUOTE,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                $responseForLog,
                'HTTP ' . $response->status() . ' — see raw_body_preview & response in flex_integration_logs',
            );

            Log::error('Flex Integration: Quote create failed', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'http_status' => $response->status(),
                'body' => self::truncateHttpBody($response->body(), 500),
            ]);

            throw new \RuntimeException(
                'Flex sales quote create failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $elementId = $data['id'] ?? $data['elementId'] ?? null;
        $elementNumber = $data['elementNumber'] ?? $data['number'] ?? null;

        if (!$elementId) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_QUOTE,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($data) ? $data : null,
                'Missing element id in response',
            );

            throw new \RuntimeException('Flex sales quote create: missing element id in response.');
        }

        $elementIdStr = (string) $elementId;

        $this->logIntegration(
            FlexIntegrationLog::ACTION_CREATE_QUOTE,
            FlexIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($data) ? $data : null,
            null,
            $elementIdStr,
        );

        Log::info('Flex Integration: Quote Created', [
            'provider_company_id' => $this->providerCompanyId,
            'rental_job_id' => $rentalJob->id,
            'rental_request_id' => $this->rentalRequestId,
            'definition_id' => $definitionId,
            'element_id' => $elementIdStr,
            'element_number' => $elementNumber,
        ]);

        return [
            'id' => $elementIdStr,
            'number' => $elementNumber !== null && $elementNumber !== '' ? (string) $elementNumber : null,
        ];
    }

    /**
     * Build ordered Flex search candidates: exact → normalized → prefix/suffix → model → meaningful singles.
     *
     * @return list<string>
     */
    public function buildProductSearchCandidates(string $productName): array
    {
        $candidates = [];
        $exact = trim(preg_replace('/\s+/u', ' ', $productName) ?? '');
        if ($exact !== '') {
            $candidates[] = $exact;
        }

        $normalized = $this->normalizeProductNameForSearch($exact !== '' ? $exact : $productName);
        if ($normalized !== '' && !$this->searchCandidateExists($candidates, $normalized)) {
            $candidates[] = $normalized;
        }

        $source = $normalized !== '' ? $normalized : $exact;
        if ($source === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $significant = array_values(array_filter($words, function (string $word): bool {
            return !$this->isInsignificantSearchToken($word);
        }));

        $wordCount = count($significant);
        if ($wordCount === 0) {
            return $candidates;
        }

        // Prefix combinations: first 2..n-1 words (e.g. "SHURE SM57")
        for ($len = $wordCount - 1; $len >= 2; $len--) {
            $prefix = implode(' ', array_slice($significant, 0, $len));
            if (!$this->searchCandidateExists($candidates, $prefix)) {
                $candidates[] = $prefix;
            }
        }

        // Suffix combinations: last 2..n-1 words (e.g. "SM57 Microphone")
        for ($len = 2; $len < $wordCount; $len++) {
            $suffix = implode(' ', array_slice($significant, -$len));
            if (!$this->searchCandidateExists($candidates, $suffix)) {
                $candidates[] = $suffix;
            }
        }

        $modelCode = ProductNormalizer::extractModelCode($source);
        if ($modelCode !== null && trim($modelCode) !== '') {
            $modelCode = trim($modelCode);
            if (!$this->searchCandidateExists($candidates, $modelCode)) {
                $candidates[] = $modelCode;
            }

            // Model code + remaining descriptive words after it (e.g. "SM57 Microphone")
            $modelIndex = $this->findTokenIndexContaining($significant, $modelCode);
            if ($modelIndex !== null && $modelIndex < $wordCount - 1) {
                $afterModel = array_slice($significant, $modelIndex);
                $modelPhrase = implode(' ', $afterModel);
                if (!$this->searchCandidateExists($candidates, $modelPhrase)) {
                    $candidates[] = $modelPhrase;
                }
            }
        }

        // Meaningful single tokens: brand (first), model-like (has digit), last descriptive word
        $singleTokens = [];
        $singleTokens[] = $significant[0];
        if ($wordCount > 1) {
            $singleTokens[] = $significant[$wordCount - 1];
        }
        foreach ($significant as $word) {
            if (preg_match('/\d/', $word)) {
                $singleTokens[] = $word;
            }
        }

        foreach ($singleTokens as $token) {
            if ($this->isInsignificantSearchToken($token)) {
                continue;
            }
            if (!$this->searchCandidateExists($candidates, $token)) {
                $candidates[] = $token;
            }
        }

        return $candidates;
    }

    /**
     * Skip filler / tiny tokens that produce noisy Flex searches.
     */
    protected function isInsignificantSearchToken(string $token): bool
    {
        $normalized = mb_strtolower(trim($token));
        if ($normalized === '' || mb_strlen($normalized) < 2) {
            return true;
        }

        static $stopwords = [
            'a', 'an', 'the', 'and', 'or', 'for', 'with', 'of', 'to', 'in', 'on', 'by', 'at',
        ];

        return in_array($normalized, $stopwords, true);
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function findTokenIndexContaining(array $tokens, string $needle): ?int
    {
        $needleNorm = mb_strtolower(preg_replace('/\s+/u', '', $needle) ?? '');
        if ($needleNorm === '') {
            return null;
        }

        foreach ($tokens as $index => $token) {
            $tokenNorm = mb_strtolower(preg_replace('/[^a-z0-9]+/iu', '', $token) ?? '');
            if ($tokenNorm !== '' && (
                $tokenNorm === $needleNorm
                || str_contains($tokenNorm, $needleNorm)
                || str_contains($needleNorm, $tokenNorm)
            )) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Normalize a product name for Flex search: strip special chars, collapse whitespace.
     * Example: "Yamaha-QL5 Digital Mixer" → "Yamaha QL5 Digital Mixer"
     */
    public function normalizeProductNameForSearch(string $productName): string
    {
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $productName) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /**
     * @param  list<string>  $candidates
     */
    protected function searchCandidateExists(array $candidates, string $candidate): bool
    {
        $needle = mb_strtolower(trim($candidate));
        foreach ($candidates as $existing) {
            if (mb_strtolower(trim($existing)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collect unique Flex inventory-model matches across all search scenarios.
     * Continues through every candidate; dedupes by Resource ID; preserves best-first order.
     *
     * @return list<array{resource_id: string, name: string, matched_by: string}>
     */
    public function collectFlexProductMatches(string $productName): array
    {
        $candidates = $this->buildProductSearchCandidates($productName);
        $matchesById = [];

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'PRODUCT_SEARCH_STRATEGIES',
            'STARTED',
            [
                'product_name' => $productName,
                'candidates' => $candidates,
            ],
            'Search all candidates and collect unique matches',
        );

        foreach ($candidates as $index => $candidate) {
            $hits = $this->searchFlexProductHits($candidate);
            foreach ($hits as $hit) {
                $resourceId = $hit['resource_id'];
                if (isset($matchesById[$resourceId])) {
                    continue;
                }

                $matchesById[$resourceId] = [
                    'resource_id' => $resourceId,
                    'name' => $hit['name'],
                    'matched_by' => $candidate,
                ];

                Log::info('Flex Integration: Collected product match from search scenario', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'strategy_index' => $index,
                    'search_text' => $candidate,
                    'flex_resource_id' => $resourceId,
                    'flex_product_name' => $hit['name'],
                ]);
            }
        }

        $matches = array_values($matchesById);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'PRODUCT_SEARCH_STRATEGIES',
            $matches === [] ? 'NOT_FOUND' : 'COLLECTED',
            [
                'product_name' => $productName,
                'candidates' => $candidates,
                'match_count' => count($matches),
                'matches' => array_map(static function (array $match): array {
                    return [
                        'resource_id' => $match['resource_id'],
                        'name' => $match['name'],
                        'matched_by' => $match['matched_by'],
                    ];
                }, $matches),
            ],
            $matches === []
                ? 'Create inventory model in Flex or await user confirmation'
                : 'Return matches for user selection or auto-resolve first hit',
        );

        return $matches;
    }

    /**
     * Try Flex product search strategies and return the best (first unique) match.
     * Used by quote-sync resolve flow where a single Resource ID is required.
     *
     * @return array{resource_id: string, flex_product_name: ?string}|null
     */
    public function searchFlexProductWithStrategies(string $productName): ?array
    {
        $matches = $this->collectFlexProductMatches($productName);
        if ($matches === []) {
            return null;
        }

        $best = $matches[0];

        return [
            'resource_id' => $best['resource_id'],
            'flex_product_name' => $best['name'],
        ];
    }

    /**
     * Search Flex and return all relevant inventory-model hits for a single search text.
     *
     * @return list<array{resource_id: string, name: string}>
     */
    public function searchFlexProductHits(string $searchText): array
    {
        $path = config('flex.global_search_path', '/f5/api/search');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $params = [
            'searchText' => $searchText,
            'searchTypes' => 'inventory-model',
            'canIncludeSerialUnits' => 'false',
            'includeDeleted' => 'false',
            'includeClosed' => 'false',
            'maxResults' => 30,
        ];

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'PRODUCT_SEARCH',
            'STARTED',
            [
                'search_text' => $searchText,
                'params' => $params,
            ],
            'Collect inventory-model hits for this search scenario',
        );

        try {
            $response = $this->flexHttp(
                'GET',
                $url,
                $params,
                FlexIntegrationLog::ACTION_SEARCH_PRODUCT,
                'Collect matches or continue next search scenario',
            );

            $data = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_API_ERROR,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $params,
                    is_array($data) ? $data : ['raw' => $response->body()],
                    'HTTP ' . $response->status(),
                );

                Log::error('Flex Integration: Product search failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'search_text' => $searchText,
                    'http_status' => $response->status(),
                ]);

                return [];
            }

            $items = self::normalizeFlexItemArray($data);
            if ($items === []) {
                $items = $data['content'] ?? $data['results'] ?? [];
            }
            if (!is_array($items) || $items === []) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_PRODUCT_NOT_FOUND,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $params,
                    is_array($data) ? $data : null,
                    'No inventory-model results',
                );

                Log::info('Flex Integration: Product Not Found', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'search_text' => $searchText,
                ]);

                FlexIntegrationDebugLog::step(
                    $this->rentalRequestId ?? 0,
                    $this->providerCompanyId,
                    'PRODUCT_SEARCH',
                    'NOT_FOUND',
                    ['search_text' => $searchText],
                    'Continue next search scenario',
                );

                return [];
            }

            $hits = [];
            $seen = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = $item['id'] ?? null;
                if ($id === null || $id === '') {
                    continue;
                }

                $idStr = (string) $id;
                if (isset($seen[$idStr])) {
                    continue;
                }

                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                if (!$this->isRelevantFlexSearchHit($searchText, $name)) {
                    continue;
                }

                $seen[$idStr] = true;
                $hits[] = [
                    'resource_id' => $idStr,
                    'name' => $name,
                ];
            }

            if ($hits === []) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_PRODUCT_NOT_FOUND,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $params,
                    is_array($data) ? $data : null,
                    'No relevant inventory-model hits for search text',
                );

                return [];
            }

            $this->logIntegration(
                FlexIntegrationLog::ACTION_SEARCH_PRODUCT,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                $params,
                ['hit_count' => count($hits), 'hits' => $hits],
                null,
                null,
                $hits[0]['resource_id'],
            );

            Log::info('Flex Integration: Product search hits collected', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'search_text' => $searchText,
                'hit_count' => count($hits),
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'PRODUCT_SEARCH',
                'FOUND',
                [
                    'search_text' => $searchText,
                    'hit_count' => count($hits),
                    'hits' => $hits,
                ],
                'Merge into unique match list',
            );

            return $hits;
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_API_ERROR,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $params,
                null,
                $e->getMessage(),
            );

            Log::error('Flex Integration: Product search exception', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'search_text' => $searchText,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Decide whether a Flex hit is relevant to the search text.
     * Accepts exact name, containment, or all significant search tokens present in the name.
     */
    protected function isRelevantFlexSearchHit(string $searchText, string $productName): bool
    {
        $needle = mb_strtolower(trim($searchText));
        $name = mb_strtolower(trim($productName));
        if ($needle === '' || $name === '') {
            return false;
        }

        if ($name === $needle || str_contains($name, $needle) || str_contains($needle, $name)) {
            return true;
        }

        $tokens = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $significant = array_values(array_filter($tokens, function (string $token): bool {
            return !$this->isInsignificantSearchToken($token);
        }));

        if ($significant === []) {
            return false;
        }

        foreach ($significant as $token) {
            if (!str_contains($name, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Search Flex inventory-model by name and return the best single hit.
     *
     * @param  bool  $exactNameOnly  When true, only accept an exact name match
     * @return array{resource_id: string, flex_product_name: ?string}|null
     */
    public function searchFlexProduct(string $searchText, bool $exactNameOnly = false): ?array
    {
        $hits = $this->searchFlexProductHits($searchText);
        if ($hits === []) {
            return null;
        }

        if ($exactNameOnly) {
            $needle = mb_strtolower(trim($searchText));
            foreach ($hits as $hit) {
                if (mb_strtolower(trim($hit['name'])) === $needle) {
                    return [
                        'resource_id' => $hit['resource_id'],
                        'flex_product_name' => $hit['name'],
                    ];
                }
            }

            return null;
        }

        return [
            'resource_id' => $hits[0]['resource_id'],
            'flex_product_name' => $hits[0]['name'],
        ];
    }

    /**
     * Resolve Flex inventory-model id for a rental request product (cached → search → create).
     */
    public function resolveFlexResourceForProduct(
        string $displayName,
        ?string $cachedFlexResourceId,
        int $productId,
    ): ?string {
        $rentalRequestId = $this->rentalRequestId ?? 0;

        FlexIntegrationDebugLog::step(
            $rentalRequestId,
            $this->providerCompanyId,
            'PRODUCT_RESOLVE',
            'STARTED',
            [
                'product_id' => $productId,
                'name' => $displayName,
                'cached_flex_resource_id' => $cachedFlexResourceId,
            ],
            'Validate cached ID, search by name, or create inventory model',
        );

        // Case 1: company inventory already has a FLEX Resource ID
        if ($cachedFlexResourceId !== null && $cachedFlexResourceId !== '') {
            if ($this->flexInventoryModelExists($cachedFlexResourceId)) {
                Log::info('Flex Integration: Reusing cached FLEX Resource ID', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $rentalRequestId,
                    'product_id' => $productId,
                    'flex_resource_id' => $cachedFlexResourceId,
                ]);

                FlexIntegrationDebugLog::step(
                    $rentalRequestId,
                    $this->providerCompanyId,
                    'PRODUCT_RESOLVE',
                    'FROM_CACHED_ID',
                    [
                        'product_id' => $productId,
                        'flex_resource_id' => $cachedFlexResourceId,
                    ],
                    'Attach resource to sales quote',
                );

                return $cachedFlexResourceId;
            }

            Log::warning('Flex Integration: Cached FLEX Resource ID invalid; falling back to search', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $rentalRequestId,
                'product_id' => $productId,
                'flex_resource_id' => $cachedFlexResourceId,
            ]);
        }

        // Case 2: multi-strategy search in FLEX inventory by product name
        $found = $this->searchFlexProductWithStrategies($displayName);
        if ($found) {
            $foundId = $found['resource_id'];
            self::persistFlexResourceOnInventory($this->providerCompanyId, $productId, $foundId);
            $this->logPersistResourceId($productId, $foundId, 'search');

            return $foundId;
        }

        // Case 3: create inventory model in FLEX
        try {
            $psmCode = Product::query()->whereKey($productId)->value('psm_code');
            $createdId = $this->createFlexInventoryModel(
                $displayName,
                $psmCode !== null && $psmCode !== '' ? (string) $psmCode : null,
            );
            self::persistFlexResourceOnInventory($this->providerCompanyId, $productId, $createdId);
            $this->logPersistResourceId($productId, $createdId, 'create');

            FlexIntegrationDebugLog::step(
                $rentalRequestId,
                $this->providerCompanyId,
                'PRODUCT_RESOLVE',
                'CREATED',
                [
                    'product_id' => $productId,
                    'flex_resource_id' => $createdId,
                    'name' => $displayName,
                ],
                'Attach newly created resource to sales quote',
            );

            return $createdId;
        } catch (\Throwable $e) {
            Log::error('Flex Integration: Product create failed', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $rentalRequestId,
                'product_id' => $productId,
                'name' => $displayName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            FlexIntegrationDebugLog::step(
                $rentalRequestId,
                $this->providerCompanyId,
                'PRODUCT_RESOLVE',
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
     * Search FLEX for a marketplace inventory product without updating the database.
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
    public function searchFlexProductForMarketplace(Equipment $equipment): array
    {
        $displayName = $this->resolveMarketplaceProductDisplayName($equipment);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'SEARCH_FLEX_PRODUCT',
            'STARTED',
            [
                'company_inventory_id' => $equipment->id,
                'company_id' => $equipment->company_id,
                'product_id' => $equipment->product_id,
                'name' => $displayName,
            ],
            'Multi-strategy FLEX search; collect all unique matches; no database updates',
        );

        $matches = $this->collectFlexProductMatches($displayName);
        $products = array_map(static function (array $match): array {
            return [
                'resource_id' => $match['resource_id'],
                'name' => $match['name'],
            ];
        }, $matches);

        if ($products === []) {
            Log::info('Flex Integration: No matching FLEX product found for marketplace search', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'name' => $displayName,
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'SEARCH_FLEX_PRODUCT',
                'NO_MATCH',
                [
                    'company_inventory_id' => $equipment->id,
                    'name' => $displayName,
                ],
                'Await user confirmation to create in FLEX',
            );

            return [
                'success' => true,
                'action' => 'no_match',
                'message' => 'No matching product found in FLEX.',
            ];
        }

        $action = count($products) === 1 ? 'single_match' : 'multiple_matches';
        $message = $action === 'single_match'
            ? 'Matching product found in FLEX.'
            : 'Multiple matching products found in FLEX. Please select one to synchronize.';

        Log::info('Flex Integration: FLEX marketplace search completed', [
            'provider_company_id' => $this->providerCompanyId,
            'company_inventory_id' => $equipment->id,
            'product_id' => $equipment->product_id,
            'action' => $action,
            'match_count' => count($products),
            'products' => $products,
            'matched_by' => array_map(static fn (array $m): string => $m['matched_by'], $matches),
        ]);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'SEARCH_FLEX_PRODUCT',
            strtoupper($action),
            [
                'company_inventory_id' => $equipment->id,
                'match_count' => count($products),
                'products' => $products,
            ],
            'Await user confirmation before linking and synchronizing',
        );

        return [
            'success' => true,
            'action' => $action,
            'products' => $products,
            'message' => $message,
        ];
    }

    /**
     * Confirm marketplace FLEX sync: link Resource ID and synchronize metadata (or create in FLEX first).
     *
     * @return array{success: bool, action: string, message: string, resource_id: string}
     *
     * @throws \InvalidArgumentException When product/name data is missing
     * @throws \RuntimeException When Flex create fails or other API errors occur
     */
    public function confirmFlexMarketplaceSync(Equipment $equipment, ?string $resourceId, bool $createIfMissing): array
    {
        $displayName = $this->resolveMarketplaceProductDisplayName($equipment);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'CONFIRM_FLEX_SYNC',
            'STARTED',
            [
                'company_inventory_id' => $equipment->id,
                'company_id' => $equipment->company_id,
                'product_id' => $equipment->product_id,
                'name' => $displayName,
                'resource_id' => $resourceId,
                'create_if_missing' => $createIfMissing,
            ],
            $createIfMissing ? 'Create inventory model in FLEX then synchronize' : 'Link Resource ID and synchronize from FLEX',
        );

        if ($createIfMissing) {
            $psmCode = $equipment->product?->psm_code;
            $createdId = $this->createFlexInventoryModel(
                $displayName,
                $psmCode !== null && $psmCode !== '' ? (string) $psmCode : null,
            );

            Log::info('Flex Integration: New FLEX product created for marketplace confirm sync', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'flex_resource_id' => $createdId,
                'name' => $displayName,
            ]);

            return $this->attemptLinkFlexResourceToEquipment($equipment, $createdId, 'create');
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

        return $this->attemptLinkFlexResourceToEquipment($equipment, $resourceId, 'search');
    }

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

        $displayName = self::productDisplayName($product);
        if (trim($displayName) === '') {
            throw new \InvalidArgumentException('Product name is empty; cannot search or create in FLEX.');
        }

        return $displayName;
    }

    /**
     * Link a FLEX Resource ID to a company_inventory row with company-scoped duplicate checks.
     *
     * @return array{success: bool, action: string, message: string, resource_id: string}
     */
    protected function attemptLinkFlexResourceToEquipment(Equipment $equipment, string $flexResourceId, string $source): array
    {
        $resourceId = trim($flexResourceId);
        $companyId = (int) $equipment->company_id;

        if ($resourceId === '') {
            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => '',
                'message' => 'FLEX Resource ID is empty; cannot link to company inventory.',
            ];
        }

        $isAlreadyLinked = (string) $equipment->flex_resource_id === $resourceId;

        if (!$isAlreadyLinked) {
            $duplicate = $this->findCompanyInventoryByFlexResourceId($companyId, $resourceId, (int) $equipment->id);
            if ($duplicate) {
                $duplicate->loadMissing(['product.brand']);
                $result = $this->buildDuplicateResourceResponse($resourceId, $duplicate);

                Log::warning('Flex Integration: FLEX Resource ID already linked to another product in company', [
                    'provider_company_id' => $this->providerCompanyId,
                    'company_inventory_id' => $equipment->id,
                    'product_id' => $equipment->product_id,
                    'flex_resource_id' => $resourceId,
                    'linked_company_inventory_id' => $duplicate->id,
                    'linked_product_id' => $duplicate->product_id,
                    'linked_product_name' => $duplicate->product
                        ? self::productDisplayName($duplicate->product)
                        : null,
                ]);

                FlexIntegrationDebugLog::step(
                    $this->rentalRequestId ?? 0,
                    $this->providerCompanyId,
                    'FETCH_FLEX_RESOURCE_ID',
                    'DUPLICATE_RESOURCE',
                    [
                        'company_inventory_id' => $equipment->id,
                        'flex_resource_id' => $resourceId,
                        'linked_company_inventory_id' => $duplicate->id,
                    ],
                    'Do not overwrite existing company_inventory.flex_resource_id',
                );

                return $result;
            }
        }

        try {
            $details = $this->fetchFlexResourceDetailsForSync($resourceId);
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => $resourceId,
                'message' => $e->getMessage(),
            ];
        }

        $action = $isAlreadyLinked
            ? 'already_linked'
            : ($source === 'create' ? 'created' : 'matched');

        return $this->finalizeFlexResourceSync(
            $equipment,
            $resourceId,
            $action,
            $details,
            assignResourceId: !$isAlreadyLinked,
            persistSource: $isAlreadyLinked ? null : $source,
        );
    }

    /**
     * Retrieve FLEX resource details for marketplace sync (single GET /inventory-model/{id}).
     *
     * @return array<string, mixed>
     */
    protected function fetchFlexResourceDetailsForSync(string $resourceId): array
    {
        try {
            $details = FlexService::getInventoryDetails($this->providerCompanyId, $resourceId);

            Log::info('Flex Integration: Resource details retrieved', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
                'name' => $details['name'] ?? null,
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'FETCH_FLEX_RESOURCE_ID',
                'DETAILS_RETRIEVED',
                [
                    'flex_resource_id' => $resourceId,
                    'name' => $details['name'] ?? null,
                ],
                'Synchronize company_inventory and inventory_master from FLEX',
            );

            return $details;
        } catch (\Throwable $e) {
            Log::error('Flex Integration: Failed to retrieve FLEX resource details', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Failed to retrieve FLEX resource details: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Persist FLEX Resource ID (when needed) and synchronize metadata within a transaction.
     *
     * @param  array<string, mixed>  $details
     * @return array{success: bool, action: string, message: string, resource_id: string}
     */
    protected function finalizeFlexResourceSync(
        Equipment $equipment,
        string $resourceId,
        string $action,
        array $details,
        bool $assignResourceId,
        ?string $persistSource = null,
    ): array {
        $companyId = (int) $equipment->company_id;

        try {
            DB::transaction(function () use ($equipment, $resourceId, $assignResourceId, $details, $companyId) {
                if ($assignResourceId) {
                    $locked = Equipment::query()->whereKey($equipment->id)->lockForUpdate()->first();
                    if (!$locked) {
                        throw new \RuntimeException('Company inventory record no longer exists.');
                    }

                    $duplicateInTransaction = Equipment::query()
                        ->where('company_id', $companyId)
                        ->where('flex_resource_id', $resourceId)
                        ->where('id', '!=', $locked->id)
                        ->lockForUpdate()
                        ->exists();

                    if ($duplicateInTransaction) {
                        throw new \RuntimeException('DUPLICATE_RESOURCE');
                    }

                    $locked->flex_resource_id = $resourceId;
                    $locked->save();

                    $equipment->flex_resource_id = $resourceId;
                }

                FlexService::synchronizeMarketplaceInventoryFromFlexDetails($equipment, $resourceId, $details);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'DUPLICATE_RESOURCE') {
                $duplicate = $this->findCompanyInventoryByFlexResourceId($companyId, $resourceId, (int) $equipment->id);
                if ($duplicate) {
                    $duplicate->loadMissing(['product.brand']);

                    return $this->buildDuplicateResourceResponse($resourceId, $duplicate);
                }
            }

            Log::error('Flex Integration: Failed to synchronize FLEX metadata', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'flex_resource_id' => $resourceId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => $resourceId,
                'message' => 'Failed to synchronize FLEX product metadata.',
            ];
        } catch (\Throwable $e) {
            Log::error('Flex Integration: Failed to synchronize FLEX metadata', [
                'provider_company_id' => $this->providerCompanyId,
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'flex_resource_id' => $resourceId,
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'action' => 'error',
                'resource_id' => $resourceId,
                'message' => 'Failed to synchronize FLEX product metadata.',
            ];
        }

        if ($persistSource !== null) {
            $this->logPersistResourceId((int) $equipment->product_id, $resourceId, $persistSource);
        }

        $message = match ($action) {
            'already_linked' => "This product is already linked to FLEX with Resource ID '{$resourceId}'.",
            'created' => 'Product was not found in FLEX. A new resource was created and linked successfully.',
            default => 'Product found in FLEX and Resource ID has been linked.',
        };

        $logEvent = match ($action) {
            'already_linked' => 'Resource ID already linked to current product; metadata synchronized from FLEX',
            'created' => 'New FLEX product created and metadata synchronized',
            default => 'FLEX product matched and metadata synchronized',
        };

        Log::info('Flex Integration: ' . $logEvent, [
            'provider_company_id' => $this->providerCompanyId,
            'company_inventory_id' => $equipment->id,
            'product_id' => $equipment->product_id,
            'flex_resource_id' => $resourceId,
            'action' => $action,
        ]);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'FETCH_FLEX_RESOURCE_ID',
            strtoupper($action),
            [
                'company_inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'flex_resource_id' => $resourceId,
            ],
            'Return synchronized Resource ID and metadata',
        );

        return [
            'success' => true,
            'action' => $action,
            'message' => $message,
            'resource_id' => $resourceId,
        ];
    }

    protected function findCompanyInventoryByFlexResourceId(
        int $companyId,
        string $flexResourceId,
        ?int $excludeInventoryId = null,
    ): ?Equipment {
        $query = Equipment::query()
            ->where('company_id', $companyId)
            ->where('flex_resource_id', trim($flexResourceId));

        if ($excludeInventoryId !== null) {
            $query->where('id', '!=', $excludeInventoryId);
        }

        return $query->first();
    }

    /**
     * @return array{success: false, action: 'duplicate_resource', message: string, resource_id: string}
     */
    protected function buildDuplicateResourceResponse(string $resourceId, Equipment $linkedEquipment): array
    {
        $linkedEquipment->loadMissing(['product.brand']);
        $linkedProductName = $linkedEquipment->product
            ? self::productDisplayName($linkedEquipment->product)
            : 'another product';

        return [
            'success' => false,
            'action' => 'duplicate_resource',
            'resource_id' => $resourceId,
            'message' => "The FLEX Resource ID '{$resourceId}' is already linked to the product '{$linkedProductName}'.",
        ];
    }

    /**
     * Validate that a FLEX inventory-model still exists (GET /inventory-model/{id}).
     */
    public function flexInventoryModelExists(string $resourceId): bool
    {
        $detailsPath = rtrim(config('flex.details_path', '/f5/api/inventory-model'), '/');
        $url = $this->baseUrl . '/' . ltrim($detailsPath, '/') . '/' . $resourceId;

        try {
            $response = $this->flexHttp(
                'GET',
                $url,
                null,
                FlexIntegrationLog::ACTION_VALIDATE_PRODUCT,
                'Reuse validated resource ID or fall back to name search',
                null,
                $resourceId,
            );

            $data = $response->json();
            $exists = $response->successful() && !empty(($data['id'] ?? $data['name'] ?? null));

            $this->logIntegration(
                FlexIntegrationLog::ACTION_VALIDATE_PRODUCT,
                $exists ? FlexIntegrationLog::STATUS_SUCCESS : FlexIntegrationLog::STATUS_FAILED,
                $url,
                ['resource_id' => $resourceId],
                is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                $exists ? null : ('HTTP ' . $response->status()),
                null,
                $resourceId,
            );

            Log::info('Flex Integration: Validate inventory model', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'flex_resource_id' => $resourceId,
                'exists' => $exists,
                'http_status' => $response->status(),
            ]);

            return $exists;
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_VALIDATE_PRODUCT,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                ['resource_id' => $resourceId],
                null,
                $e->getMessage(),
                null,
                $resourceId,
            );

            Log::warning('Flex Integration: Validate inventory model exception', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * GET /inventory-group/list — use the group named "Non-Serialized Model".
     */
    public function getNonSerializedModelInventoryGroupId(): string
    {
        $expectedName = (string) config('flex.inventory_model_group_name', 'Non-Serialized Model');
        $cacheKey = 'flex_inventory_group_' . $this->providerCompanyId . '_' . md5(mb_strtolower($expectedName));
        $ttl = (int) config('flex.inventory_group_cache_ttl', 86400);

        return Cache::remember($cacheKey, $ttl, function () use ($expectedName) {
            $path = config('flex.inventory_group_list_path', '/f5/api/inventory-group/list');
            $url = $this->baseUrl . '/' . ltrim($path, '/');

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'INVENTORY_GROUP',
                'STARTED',
                [
                    'api_url' => $url,
                    'expected_group_name' => $expectedName,
                ],
                'Select inventory group by name for product create',
            );

            $response = $this->flexHttp(
                'GET',
                $url,
                null,
                FlexIntegrationLog::ACTION_FETCH_INVENTORY_GROUP,
                'Create inventory model under the selected groupId',
            );

            $data = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_FETCH_INVENTORY_GROUP,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    ['expected_group_name' => $expectedName],
                    is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                    'HTTP ' . $response->status(),
                );

                Log::error('Flex Integration: Inventory group list failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'expected_group_name' => $expectedName,
                    'http_status' => $response->status(),
                    'body' => self::truncateHttpBody($response->body(), 500),
                ]);

                throw new \RuntimeException(
                    'Flex inventory group list failed: HTTP ' . $response->status()
                );
            }

            $items = self::normalizeFlexItemArray($data);
            $matched = null;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (strcasecmp(trim((string) ($item['name'] ?? '')), $expectedName) === 0) {
                    $matched = $item;
                    break;
                }
            }

            if ($matched === null || empty($matched['id'])) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_FETCH_INVENTORY_GROUP,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    ['expected_group_name' => $expectedName],
                    is_array($data) ? $data : null,
                    'Inventory group named "' . $expectedName . '" not found',
                );

                Log::error('Flex Integration: Inventory group not found by name', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'expected_group_name' => $expectedName,
                    'groups_count' => count($items),
                ]);

                throw new \RuntimeException(
                    'Flex inventory group named "' . $expectedName . '" was not found. Cannot create inventory models.'
                );
            }

            $groupId = (string) $matched['id'];

            $this->logIntegration(
                FlexIntegrationLog::ACTION_FETCH_INVENTORY_GROUP,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                ['expected_group_name' => $expectedName],
                [
                    'selected_group_id' => $groupId,
                    'selected_group_name' => $matched['name'] ?? $expectedName,
                    'parentGroupId' => $matched['parentGroupId'] ?? null,
                ],
            );

            Log::info('Flex Integration: Selected inventory group', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'group_id' => $groupId,
                'group_name' => $matched['name'] ?? $expectedName,
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'INVENTORY_GROUP',
                'SELECTED',
                [
                    'group_id' => $groupId,
                    'group_name' => $matched['name'] ?? $expectedName,
                ],
                'POST inventory-model with this groupId',
            );

            return $groupId;
        });
    }

    /**
     * @deprecated Use getNonSerializedModelInventoryGroupId()
     */
    public function getRootInventoryGroupId(): string
    {
        return $this->getNonSerializedModelInventoryGroupId();
    }

    /**
     * POST /inventory-model — create a new FLEX inventory model under "Non-Serialized Model".
     * After a successful create, best-effort populate Pro Subrental Marketplace custom fields.
     */
    public function createFlexInventoryModel(string $productName, ?string $psmCode = null): string
    {
        $groupId = $this->getNonSerializedModelInventoryGroupId();
        $path = config('flex.inventory_model_create_path', '/f5/api/inventory-model');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $payload = [
            'name' => $productName,
            'groupId' => $groupId,
        ];

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'PRODUCT_CREATE',
            'STARTED',
            [
                'name' => $productName,
                'groupId' => $groupId,
                'psm_code' => $psmCode,
            ],
            'POST /f5/api/inventory-model then persist flex_resource_id',
        );

        $response = $this->flexHttp(
            'POST',
            $url,
            $payload,
            FlexIntegrationLog::ACTION_CREATE_PRODUCT,
            'Persist new flex_resource_id on company_inventory and attach to quote',
        );

        $data = $response->json();

        if (!$response->successful()) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_PRODUCT,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                'HTTP ' . $response->status(),
            );

            Log::error('Flex Integration: Create inventory model failed', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'payload' => $payload,
                'http_status' => $response->status(),
                'body' => self::truncateHttpBody($response->body(), 500),
            ]);

            throw new \RuntimeException(
                'Flex inventory model create failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $id = $data['id'] ?? null;
        if ($id === null || $id === '') {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_PRODUCT,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                is_array($data) ? $data : null,
                'Missing id in create inventory-model response',
            );

            throw new \RuntimeException('Flex inventory model create: missing id in response.');
        }

        $idStr = (string) $id;

        $this->logIntegration(
            FlexIntegrationLog::ACTION_CREATE_PRODUCT,
            FlexIntegrationLog::STATUS_SUCCESS,
            $url,
            $payload,
            is_array($data) ? $data : null,
            null,
            null,
            $idStr,
        );

        Log::info('Flex Integration: Product Created', [
            'provider_company_id' => $this->providerCompanyId,
            'rental_request_id' => $this->rentalRequestId,
            'name' => $productName,
            'group_id' => $groupId,
            'flex_resource_id' => $idStr,
        ]);

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'PRODUCT_CREATE',
            'SUCCESS',
            [
                'name' => $productName,
                'groupId' => $groupId,
                'flex_resource_id' => $idStr,
            ],
            'Populate Pro Subrental Marketplace custom fields then persist flex_resource_id',
        );

        $this->populateProSubrentalMarketplaceCustomFieldsAfterCreate($idStr, $psmCode);

        return $idStr;
    }

    /**
     * After creating a FLEX inventory model, set PSM Code and Publish to PSM custom fields.
     * Soft-fails: logs errors and never throws (product create remains successful).
     */
    public function populateProSubrentalMarketplaceCustomFieldsAfterCreate(
        string $resourceId,
        ?string $psmCode = null,
    ): void {
        try {
            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'CUSTOM_FIELDS',
                'STARTED',
                [
                    'flex_resource_id' => $resourceId,
                    'psm_code' => $psmCode,
                ],
                'Resolve Pro Subrental Marketplace group and fieldDefIds',
            );

            $customFieldGroupId = $this->resolveProSubrentalMarketplaceCustomFieldGroupId($resourceId);
            if ($customFieldGroupId === null) {
                Log::warning('Flex Integration: Pro Subrental Marketplace custom field group not found after product create', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'flex_resource_id' => $resourceId,
                ]);

                return;
            }

            $fieldDefs = $this->resolveProSubrentalMarketplaceFieldDefIds($customFieldGroupId, $resourceId);
            $psmCodeFieldDefId = $fieldDefs['psm_code_field_def_id'] ?? null;
            $publishToPsmFieldDefId = $fieldDefs['publish_to_psm_field_def_id'] ?? null;

            if ($psmCodeFieldDefId === null && $publishToPsmFieldDefId === null) {
                Log::warning('Flex Integration: Required Pro Subrental custom fields not found after product create', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'flex_resource_id' => $resourceId,
                    'custom_field_group_id' => $customFieldGroupId,
                ]);

                return;
            }

            $trimmedPsmCode = trim((string) ($psmCode ?? ''));
            if ($psmCodeFieldDefId !== null) {
                if ($trimmedPsmCode !== '') {
                    $this->saveFlexCustomFieldValue($resourceId, $psmCodeFieldDefId, $trimmedPsmCode, 'PSM Code');
                } else {
                    Log::warning('Flex Integration: Skipping PSM Code custom field update — empty psm_code', [
                        'provider_company_id' => $this->providerCompanyId,
                        'rental_request_id' => $this->rentalRequestId,
                        'flex_resource_id' => $resourceId,
                        'field_def_id' => $psmCodeFieldDefId,
                    ]);
                }
            } else {
                Log::warning('Flex Integration: PSM Code fieldDefId not found in custom field group', [
                    'provider_company_id' => $this->providerCompanyId,
                    'flex_resource_id' => $resourceId,
                    'custom_field_group_id' => $customFieldGroupId,
                ]);
            }

            if ($publishToPsmFieldDefId !== null) {
                $this->saveFlexCustomFieldValue($resourceId, $publishToPsmFieldDefId, 'true', 'Publish to PSM');
            } else {
                Log::warning('Flex Integration: Publish to PSM fieldDefId not found in custom field group', [
                    'provider_company_id' => $this->providerCompanyId,
                    'flex_resource_id' => $resourceId,
                    'custom_field_group_id' => $customFieldGroupId,
                ]);
            }

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'CUSTOM_FIELDS',
                'FINISHED',
                [
                    'flex_resource_id' => $resourceId,
                    'custom_field_group_id' => $customFieldGroupId,
                    'psm_code_updated' => $psmCodeFieldDefId !== null && $trimmedPsmCode !== '',
                    'publish_to_psm_updated' => $publishToPsmFieldDefId !== null,
                ],
                'Continue with persist flex_resource_id / attach to quote',
            );
        } catch (\Throwable $e) {
            Log::error('Flex Integration: Custom field population failed after product create (non-blocking)', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'flex_resource_id' => $resourceId,
                'psm_code' => $psmCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'CUSTOM_FIELDS',
                'FAILED',
                [
                    'flex_resource_id' => $resourceId,
                    'error' => $e->getMessage(),
                ],
                'Continue — product create remains successful',
            );
        }
    }

    /**
     * GET /custom-field-group/inventory-model/groups?resourceId= — find "Pro Subrental Marketplace" group id.
     */
    protected function resolveProSubrentalMarketplaceCustomFieldGroupId(string $resourceId): ?string
    {
        $path = config(
            'flex.custom_field_inventory_model_groups_path',
            '/f5/api/custom-field-group/inventory-model/groups'
        );
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $params = ['resourceId' => $resourceId];

        Log::info('Flex Integration: Looking up Pro Subrental Marketplace custom field group', [
            'provider_company_id' => $this->providerCompanyId,
            'flex_resource_id' => $resourceId,
            'url' => $url,
        ]);

        try {
            $response = $this->flexHttp(
                'GET',
                $url,
                $params,
                FlexIntegrationLog::ACTION_UPDATE_CUSTOM_FIELD,
                'Resolve PSM Code and Publish to PSM fieldDefIds',
                null,
                $resourceId,
            );

            $data = $response->json();
            if (!$response->successful()) {
                Log::error('Flex Integration: Custom field group lookup failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'flex_resource_id' => $resourceId,
                    'http_status' => $response->status(),
                    'body' => self::truncateHttpBody($response->body(), 500),
                ]);

                return null;
            }

            $groups = self::normalizeFlexItemArray($data);
            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $name = isset($group['name']) ? trim((string) $group['name']) : '';
                if (strcasecmp($name, 'Pro Subrental Marketplace') === 0) {
                    $id = $group['id'] ?? null;
                    if ($id !== null && $id !== '') {
                        Log::info('Flex Integration: Custom field group resolved', [
                            'provider_company_id' => $this->providerCompanyId,
                            'flex_resource_id' => $resourceId,
                            'custom_field_group_id' => (string) $id,
                        ]);

                        return (string) $id;
                    }
                }
            }

            Log::warning('Flex Integration: Custom field group "Pro Subrental Marketplace" missing in response', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('Flex Integration: Custom field group lookup exception', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * GET /custom-field-value/{groupId}/resource-values?resourceId= — resolve fieldDefIds by caption.
     *
     * @return array{psm_code_field_def_id: ?string, publish_to_psm_field_def_id: ?string}
     */
    protected function resolveProSubrentalMarketplaceFieldDefIds(string $customFieldGroupId, string $resourceId): array
    {
        $result = [
            'psm_code_field_def_id' => null,
            'publish_to_psm_field_def_id' => null,
        ];

        $pattern = config(
            'flex.custom_field_resource_values_path_pattern',
            '/f5/api/custom-field-value/%s/resource-values'
        );
        $path = sprintf($pattern, $customFieldGroupId);
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $params = ['resourceId' => $resourceId];

        Log::info('Flex Integration: Looking up Pro Subrental Marketplace custom fields', [
            'provider_company_id' => $this->providerCompanyId,
            'flex_resource_id' => $resourceId,
            'custom_field_group_id' => $customFieldGroupId,
            'url' => $url,
        ]);

        try {
            $response = $this->flexHttp(
                'GET',
                $url,
                $params,
                FlexIntegrationLog::ACTION_UPDATE_CUSTOM_FIELD,
                'POST PSM Code and Publish to PSM custom field values',
                null,
                $resourceId,
            );

            $data = $response->json();
            if (!$response->successful()) {
                Log::error('Flex Integration: Custom field lookup failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'flex_resource_id' => $resourceId,
                    'custom_field_group_id' => $customFieldGroupId,
                    'http_status' => $response->status(),
                    'body' => self::truncateHttpBody($response->body(), 500),
                ]);

                return $result;
            }

            $rows = self::normalizeFlexItemArray($data);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $caption = isset($row['caption']) ? trim((string) $row['caption']) : '';
                $fieldDefId = $row['fieldDefId'] ?? null;
                if ($fieldDefId === null || $fieldDefId === '') {
                    continue;
                }
                if (strcasecmp($caption, 'PSM Code') === 0) {
                    $result['psm_code_field_def_id'] = (string) $fieldDefId;
                }
                if (strcasecmp($caption, 'Publish to PSM') === 0) {
                    $result['publish_to_psm_field_def_id'] = (string) $fieldDefId;
                }
            }

            Log::info('Flex Integration: Custom fields resolved by caption', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
                'custom_field_group_id' => $customFieldGroupId,
                'psm_code_field_def_id' => $result['psm_code_field_def_id'],
                'publish_to_psm_field_def_id' => $result['publish_to_psm_field_def_id'],
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('Flex Integration: Custom field lookup exception', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
                'custom_field_group_id' => $customFieldGroupId,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }
    }

    /**
     * POST /custom-field-value/resource/{resourceId} — set a single custom field value.
     */
    protected function saveFlexCustomFieldValue(
        string $resourceId,
        string $fieldDefId,
        string $value,
        string $fieldCaption,
    ): bool {
        $pattern = config(
            'flex.custom_field_resource_value_save_path_pattern',
            '/f5/api/custom-field-value/resource/%s'
        );
        $path = sprintf($pattern, $resourceId);
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $payload = [
            'fieldDefId' => $fieldDefId,
            'value' => $value,
        ];

        Log::info('Flex Integration: Updating custom field', [
            'provider_company_id' => $this->providerCompanyId,
            'rental_request_id' => $this->rentalRequestId,
            'flex_resource_id' => $resourceId,
            'field_caption' => $fieldCaption,
            'field_def_id' => $fieldDefId,
            'value' => $value,
        ]);

        try {
            $response = $this->flexHttp(
                'POST',
                $url,
                $payload,
                FlexIntegrationLog::ACTION_UPDATE_CUSTOM_FIELD,
                'Continue remaining custom field updates or finish',
                null,
                $resourceId,
                'query',
            );

            $data = $response->json();
            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_UPDATE_CUSTOM_FIELD,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $payload,
                    is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                    'HTTP ' . $response->status() . ' updating ' . $fieldCaption,
                    null,
                    $resourceId,
                );

                Log::error('Flex Integration: Custom field update failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'flex_resource_id' => $resourceId,
                    'field_caption' => $fieldCaption,
                    'field_def_id' => $fieldDefId,
                    'http_status' => $response->status(),
                    'body' => self::truncateHttpBody($response->body(), 500),
                ]);

                return false;
            }

            $this->logIntegration(
                FlexIntegrationLog::ACTION_UPDATE_CUSTOM_FIELD,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                $payload,
                is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                null,
                null,
                $resourceId,
            );

            Log::info('Flex Integration: Custom field updated successfully', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
                'field_caption' => $fieldCaption,
                'field_def_id' => $fieldDefId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Flex Integration: Custom field update exception', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $resourceId,
                'field_caption' => $fieldCaption,
                'field_def_id' => $fieldDefId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * GET /element-definition/identity — resolve Quote definitionId by name.
     */
    public function getSalesQuoteDefinitionId(): string
    {
        $cacheKey = 'flex_quote_definition_id_' . $this->providerCompanyId;
        $ttl = (int) config('flex.element_definition_cache_ttl', 86400);

        return Cache::remember($cacheKey, $ttl, function () {
            $path = config('flex.element_definition_identity_path', '/f5/api/element-definition/identity');
            $url = $this->baseUrl . '/' . ltrim($path, '/');

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'DEFINITION_ID',
                'STARTED',
                ['api_url' => $url],
                'Find element definition where name equals Quote',
            );

            $response = $this->flexHttp(
                'GET',
                $url,
                null,
                FlexIntegrationLog::ACTION_FETCH_DEFINITION_ID,
                'Use selected Quote definitionId when creating sales quote',
            );

            $data = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_FETCH_DEFINITION_ID,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    null,
                    is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                    'HTTP ' . $response->status(),
                );

                Log::error('Flex Integration: element-definition/identity failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'http_status' => $response->status(),
                    'body' => self::truncateHttpBody($response->body(), 500),
                ]);

                throw new \RuntimeException(
                    'Flex element-definition list failed: HTTP ' . $response->status()
                    . '. Cannot resolve Quote definitionId.'
                );
            }

            $items = self::normalizeFlexItemArray($data);
            $quoteDef = null;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (strcasecmp(trim((string) ($item['name'] ?? '')), 'Quote') === 0) {
                    $quoteDef = $item;
                    break;
                }
            }

            if ($quoteDef === null || empty($quoteDef['id'])) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_FETCH_DEFINITION_ID,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    null,
                    is_array($data) ? $data : null,
                    'No element definition with name "Quote" found',
                );

                Log::error('Flex Integration: Quote definition not found in element-definition list', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'definitions_count' => count($items),
                ]);

                throw new \RuntimeException(
                    'Flex element-definition list does not contain a definition named "Quote". Cannot create sales quote.'
                );
            }

            $definitionId = (string) $quoteDef['id'];

            $this->logIntegration(
                FlexIntegrationLog::ACTION_FETCH_DEFINITION_ID,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                null,
                [
                    'selected_definition_id' => $definitionId,
                    'selected_name' => $quoteDef['name'] ?? 'Quote',
                ],
            );

            Log::info('Flex Integration: Selected Quote definitionId', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'definition_id' => $definitionId,
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'DEFINITION_ID',
                'SELECTED',
                ['definition_id' => $definitionId],
                'Create sales quote with this definitionId',
            );

            return $definitionId;
        });
    }

    /**
     * @return array{flex_product_id: ?string, response: array|null}
     */
    public function attachProductToSalesQuote(string $salesQuoteId, string $resourceId, int $quantity): array
    {
        $base = rtrim($this->baseUrl . '/' . ltrim(config('flex.financial_line_item_path', '/f5/api/financial-document-line-item'), '/'), '/');
        $url = $base . '/' . $salesQuoteId . '/add-resource/' . $resourceId;

        // Flex Swagger expects these as query params with an empty POST body (not JSON).
        $requestPayload = [
            'resourceParentId' => '',
            'managedResourceLineItemType' => 'inventory-model',
            'quantity' => $quantity,
        ];

        $response = $this->flexHttp(
            'POST',
            $url,
            $requestPayload,
            FlexIntegrationLog::ACTION_ADD_PRODUCT_TO_QUOTE,
            'Track fin-doc-quick-line-added event (or process next product line)',
            $salesQuoteId,
            $resourceId,
            'query',
        );

        $data = $response->json();

        if (!$response->successful()) {
            $respLog = is_array($data) ? $data : [];
            $respLog['http_status'] = $response->status();
            $respLog['raw_body_preview'] = self::truncateHttpBody($response->body());

            $this->logIntegration(
                FlexIntegrationLog::ACTION_ADD_PRODUCT_TO_QUOTE,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $requestPayload,
                $respLog,
                'HTTP ' . $response->status() . ' — see raw_body_preview in flex_integration_logs',
                $salesQuoteId,
                $resourceId,
            );

            throw new \RuntimeException(
                'Flex attach line item failed: HTTP ' . $response->status() . ' — '
                . self::truncateHttpBody($response->body(), 500)
            );
        }

        $lineId = $data['id']
            ?? $data['lineItemId']
            ?? $data['financialDocumentLineItemId']
            ?? (is_array($data['addedResourceLineIds'] ?? null) ? ($data['addedResourceLineIds'][0] ?? null) : null);
        $lineIdStr = $lineId !== null && $lineId !== '' ? (string) $lineId : null;

        $this->logIntegration(
            FlexIntegrationLog::ACTION_ADD_PRODUCT_TO_QUOTE,
            FlexIntegrationLog::STATUS_SUCCESS,
            $url,
            $requestPayload,
            is_array($data) ? $data : null,
            null,
            $salesQuoteId,
            $lineIdStr ?? $resourceId,
        );

        Log::info('Flex Integration: Product Added', [
            'provider_company_id' => $this->providerCompanyId,
            'rental_request_id' => $this->rentalRequestId,
            'sales_quote_id' => $salesQuoteId,
            'resource_id' => $resourceId,
            'line_id' => $lineIdStr,
            'quantity' => $quantity,
            'response' => is_array($data) ? $data : null,
        ]);

        return [
            'flex_product_id' => $lineIdStr,
            'response' => is_array($data) ? $data : null,
        ];
    }

    /**
     * POST /financial-document/{quoteId}/address-data — set Quote Venue (right address).
     * Soft-fails: logs errors and continues.
     */
    public function setQuoteVenueAddress(string $quoteId, RentalJob $rentalJob): bool
    {
        $addressFreeText = self::resolveQuoteVenueAddressText($rentalJob);
        if ($addressFreeText === null) {
            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'QUOTE_VENUE',
                'SKIPPED',
                [
                    'flex_quote_id' => $quoteId,
                    'reason' => 'no_delivery_or_shipping_address',
                    'shipping_method' => $rentalJob->shipping_method,
                ],
                'Continue with quote notes / product attach',
            );

            Log::info('Flex Integration: Quote venue skipped — no address on rental request', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'flex_quote_id' => $quoteId,
            ]);

            return false;
        }

        $pattern = config(
            'flex.financial_document_address_path_pattern',
            '/f5/api/financial-document/%s/address-data'
        );
        $path = sprintf($pattern, $quoteId);
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $payload = [
            'addressLocation' => 'right',
            'addressFreeText' => $addressFreeText,
        ];

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'QUOTE_VENUE',
            'STARTED',
            [
                'flex_quote_id' => $quoteId,
                'payload' => $payload,
            ],
            'POST financial-document address-data',
        );

        try {
            // Flex Swagger expects addressLocation / addressFreeText as query params with an empty POST body.
            $response = $this->flexHttp(
                'POST',
                $url,
                $payload,
                FlexIntegrationLog::ACTION_SET_QUOTE_ADDRESS,
                'Add rental request messages as quote note (or attach products)',
                $quoteId,
                null,
                'query',
            );

            $data = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_SET_QUOTE_ADDRESS,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $payload,
                    is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                    'HTTP ' . $response->status(),
                    $quoteId,
                );

                Log::error('Flex Integration: Quote venue address failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'flex_quote_id' => $quoteId,
                    'http_status' => $response->status(),
                    'payload' => $payload,
                    'body' => self::truncateHttpBody($response->body(), 500),
                ]);

                return false;
            }

            $this->logIntegration(
                FlexIntegrationLog::ACTION_SET_QUOTE_ADDRESS,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                $payload,
                is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                null,
                $quoteId,
            );

            Log::info('Flex Integration: Quote venue address set', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'flex_quote_id' => $quoteId,
                'address_free_text' => $addressFreeText,
                'response' => is_array($data) ? $data : null,
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'QUOTE_VENUE',
                'SUCCESS',
                [
                    'flex_quote_id' => $quoteId,
                    'address_free_text' => $addressFreeText,
                ],
                'Add rental request messages as quote note (or attach products)',
            );

            return true;
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_SET_QUOTE_ADDRESS,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                null,
                $e->getMessage(),
                $quoteId,
            );

            Log::error('Flex Integration: Quote venue address exception', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'flex_quote_id' => $quoteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'QUOTE_VENUE',
                'FAILED',
                [
                    'flex_quote_id' => $quoteId,
                    'error' => $e->getMessage(),
                ],
                'Continue quote flow despite venue failure',
            );

            return false;
        }
    }

    /**
     * Prefer delivery address; fall back to shipping-style address text when present.
     * PSM stores both delivery/ship-to-site addresses on rental_jobs.delivery_address.
     */
    public static function resolveQuoteVenueAddressText(RentalJob $rentalJob): ?string
    {
        $deliveryAddress = trim((string) ($rentalJob->delivery_address ?? ''));
        if ($deliveryAddress !== '') {
            return $deliveryAddress;
        }

        // No separate shipping_address column; treat empty delivery_address as skip.
        return null;
    }

    /**
     * POST /element-notification — attach combined public/private messages as a Quote note.
     * Soft-fails: logs errors and continues.
     */
    public function addQuoteNoteFromRentalMessages(
        string $quoteId,
        RentalJob $rentalJob,
        SupplyJob $supplyJob,
    ): bool {
        $notes = self::buildCombinedQuoteNote($rentalJob, $supplyJob);
        if ($notes === null) {
            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'QUOTE_NOTE',
                'SKIPPED',
                [
                    'flex_quote_id' => $quoteId,
                    'reason' => 'no_global_private_or_offer_requirements_message',
                ],
                'Continue with product attach',
            );

            Log::info('Flex Integration: Quote note skipped — no messages', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'flex_quote_id' => $quoteId,
                'supply_job_id' => $supplyJob->id,
            ]);

            return false;
        }

        $path = config('flex.element_notification_path', '/f5/api/element-notification');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $payload = [
            'projectElementId' => $quoteId,
            'notes' => $notes,
            'resourceId' => null,
        ];

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'QUOTE_NOTE',
            'STARTED',
            [
                'flex_quote_id' => $quoteId,
                'combined_notes' => $notes,
                'payload' => $payload,
            ],
            'POST element-notification',
        );

        try {
            $response = $this->flexHttp(
                'POST',
                $url,
                $payload,
                FlexIntegrationLog::ACTION_CREATE_QUOTE_NOTE,
                'Attach inventory line items to the sales quote',
                $quoteId,
            );

            $data = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_CREATE_QUOTE_NOTE,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $payload,
                    is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                    'HTTP ' . $response->status(),
                    $quoteId,
                );

                Log::error('Flex Integration: Quote note create failed', [
                    'provider_company_id' => $this->providerCompanyId,
                    'rental_request_id' => $this->rentalRequestId,
                    'flex_quote_id' => $quoteId,
                    'http_status' => $response->status(),
                    'payload' => $payload,
                    'body' => self::truncateHttpBody($response->body(), 500),
                ]);

                return false;
            }

            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_QUOTE_NOTE,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                $payload,
                is_array($data) ? $data : ['raw' => self::truncateHttpBody($response->body())],
                null,
                $quoteId,
            );

            Log::info('Flex Integration: Quote note created', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'flex_quote_id' => $quoteId,
                'notes' => $notes,
                'response' => is_array($data) ? $data : null,
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'QUOTE_NOTE',
                'SUCCESS',
                [
                    'flex_quote_id' => $quoteId,
                    'notes_length' => strlen($notes),
                ],
                'Attach inventory line items to the sales quote',
            );

            return true;
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_QUOTE_NOTE,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                null,
                $e->getMessage(),
                $quoteId,
            );

            Log::error('Flex Integration: Quote note exception', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $this->rentalRequestId,
                'flex_quote_id' => $quoteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            FlexIntegrationDebugLog::step(
                $this->rentalRequestId ?? 0,
                $this->providerCompanyId,
                'QUOTE_NOTE',
                'FAILED',
                [
                    'flex_quote_id' => $quoteId,
                    'error' => $e->getMessage(),
                ],
                'Continue quote flow despite note failure',
            );

            return false;
        }
    }

    /**
     * Combine Global Message, Private Message, and Offer Requirements into one note body.
     * Empty/null sections are omitted.
     */
    public static function buildCombinedQuoteNote(RentalJob $rentalJob, SupplyJob $supplyJob): ?string
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

        $sections = [];
        if ($globalMessage !== '') {
            $sections[] = "Global Message:\n" . $globalMessage;
        }
        if ($privateMessage !== '') {
            $sections[] = "Private Message:\n" . $privateMessage;
        }
        if ($offerRequirements !== '') {
            $sections[] = "Offer Requirements:\n" . $offerRequirements;
        }

        if ($sections === []) {
            return null;
        }

        return implode("\n\n", $sections);
    }

    public function trackFinDocQuickLineAdded(): void
    {
        $path = config('flex.user_event_tracking_path', '/f5/api/user-event-tracking');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $payload = ['eventType' => 'fin-doc-quick-line-added'];

        try {
            $response = $this->flexHttp(
                'POST',
                $url,
                $payload,
                FlexIntegrationLog::ACTION_TRACK_EVENT,
                'Continue with next product line or finalize supply job sync',
            );

            $body = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_TRACK_EVENT,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $payload,
                    is_array($body) ? $body : ['raw' => $response->body()],
                    'HTTP ' . $response->status(),
                );

                return;
            }

            $this->logIntegration(
                FlexIntegrationLog::ACTION_TRACK_EVENT,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                $payload,
                is_array($body) ? $body : null,
            );
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_TRACK_EVENT,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $payload,
                null,
                $e->getMessage(),
            );
        }
    }

    public function sendMissingProductsEmail(Company $provider, RentalJob $rentalJob, array $missingLines): void
    {
        if ($missingLines === []) {
            return;
        }

        $provider->loadMissing('getDefaultcontact');
        $to = $provider->getDefaultcontact->email ?? null;
        if (!$to) {
            Log::warning('Flex integration: cannot email provider about missing Flex products (no default contact email).', [
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

        $body = '<p>These requested rental products are not available in Flex inventory:</p>' . $listHtml
            . '<p>Rental request ID: ' . (int) $rentalJob->id . '</p>'
            . '<p>Rental request name: ' . e($rentalJob->name) . '</p>';

        Mail::html($body, function ($message) use ($to) {
            $message->to($to)
                ->subject('Flex: rental products not found in inventory');
        });

        Log::info('Flex integration: Missing products email sent to provider', [
            'provider_company_id' => $provider->id,
            'rental_job_id' => $rentalJob->id,
        ]);
    }

    protected function quoteSettings(): array
    {
        return [
            'currency_id' => null,
            'status_id' => config('flex.sales_quote_status_id'),
            'person_responsible_id' => config('flex.sales_quote_person_responsible_id'),
            'location_id' => config('flex.sales_quote_location_id'),
            'default_pricing_model_id' => config('flex.sales_quote_default_pricing_model_id'),
        ];
    }

    protected function authHeaders(): array
    {
        $authType = config('flex.auth_header', 'x_auth');
        if ($authType === 'x_auth') {
            return ['X-Auth-Token' => $this->apiKey];
        }

        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    protected function searchContact(string $searchText): ?string
    {
        $path = config('flex.contact_search_path', '/f5/api/contact/search');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $params = ['searchText' => $searchText];

        try {
            $response = $this->flexHttp(
                'GET',
                $url,
                $params,
                FlexIntegrationLog::ACTION_CREATE_CLIENT,
                'Reuse found Flex contact as clientId by name (or create contact if not found)',
            );

            $data = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_CREATE_CLIENT,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $params,
                    is_array($data) ? $data : ['raw' => $response->body()],
                    'Contact search HTTP ' . $response->status(),
                );

                return null;
            }

            $content = $data['content'] ?? [];
            if (!is_array($content) || $content === []) {
                return null;
            }

            $id = $content[0]['id'] ?? null;
            if ($id === null || $id === '') {
                return null;
            }

            $idStr = (string) $id;

            $this->logIntegration(
                FlexIntegrationLog::ACTION_CREATE_CLIENT,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                $params,
                ['clientId' => $idStr, 'matched' => 'search'],
            );

            return $idStr;
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_API_ERROR,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $params,
                null,
                $e->getMessage(),
            );

            return null;
        }
    }

    protected function getClientResourceTypeId(): ?string
    {
        $type = $this->getClientResourceType();

        return isset($type['id']) ? (string) $type['id'] : null;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    protected function getClientResourceType(): ?array
    {
        $cacheKey = 'flex_client_resource_type_' . $this->providerCompanyId;
        $ttl = (int) config('flex.client_resource_type_cache_ttl', 86400);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['id'])) {
            return [
                'id' => (string) $cached['id'],
                'name' => (string) ($cached['name'] ?? 'Client'),
            ];
        }
        if (is_string($cached) && $cached !== '') {
            return ['id' => $cached, 'name' => 'Client'];
        }

        // Legacy cache key from earlier releases.
        $legacyCached = Cache::get('flex_client_resource_type_id_' . $this->providerCompanyId);
        if (is_string($legacyCached) && $legacyCached !== '') {
            $resolved = ['id' => $legacyCached, 'name' => 'Client'];
            Cache::put($cacheKey, $resolved, $ttl);

            return $resolved;
        }

        $path = config('flex.resource_type_path', '/f5/api/resource-type/nodes');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $params = array_filter(
            config('flex.resource_type_query', ['classname' => 'resource-type', 'nodeId' => 'root']),
            static fn ($v) => $v !== null && $v !== '',
        );

        try {
            $response = $this->flexHttp(
                'GET',
                $url,
                $params !== [] ? $params : null,
                FlexIntegrationLog::ACTION_DIAGNOSTIC,
                'Use Client resource type id when creating Flex contact',
            );

            $data = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_API_ERROR,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $params,
                    is_array($data) ? $data : ['raw' => $response->body()],
                    'Resource type HTTP ' . $response->status(),
                );

                return null;
            }

            $items = self::normalizeFlexItemArray($data);
            if ($items === []) {
                return null;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!empty($item['deleted'])) {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '' && strcasecmp($name, 'Client') === 0) {
                    $id = $item['id'] ?? null;
                    if ($id !== null && $id !== '') {
                        $resolved = [
                            'id' => (string) $id,
                            'name' => $name,
                        ];
                        Cache::put($cacheKey, $resolved, $ttl);

                        return $resolved;
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_API_ERROR,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                $params,
                null,
                $e->getMessage(),
            );

            return null;
        }
    }

    /**
     * Build Flex POST /contact payload from requester profile and requester company address.
     *
     * @param  array{id: string, name: string}  $clientResourceType
     * @return array<string, mixed>
     */
    protected static function buildFlexContactCreatePayload(
        User $requester,
        ?UserProfile $profile,
        array $clientResourceType,
    ): array {
        $firstName = trim((string) ($profile->first_name ?? ''));
        $lastName = trim((string) ($profile->last_name ?? ''));
        $email = trim((string) ($profile->email ?? $requester->email ?? ''));
        $mobile = trim((string) ($profile->mobile ?? ''));

        $company = $requester->company;
        if ($company !== null) {
            $company->loadMissing(['country', 'state', 'city']);
        }

        $country = $company?->country;
        $phoneEntry = self::buildFlexPhoneEntry($mobile, $country);
        $addressEntry = $company ? self::buildFlexContactAddress($company) : null;

        $payload = [
            'salutation' => 'None',
            'firstName' => $firstName !== '' ? $firstName : ($requester->username ?? 'Customer'),
            'middleName' => '',
            'lastName' => $lastName !== '' ? $lastName : '—',
            'employerId' => null,
            'birthday' => null,
            'defaultTermsInherited' => true,
            'organization' => false,
            'jobTitle' => null,
            'assignedNumber' => null,
            'tagIds' => null,
            'defaultBillToContact' => true,
            'resourceTypes' => [
                [
                    'id' => $clientResourceType['id'],
                    'name' => $clientResourceType['name'],
                ],
            ],
            'phoneNumbers' => $phoneEntry !== null ? [$phoneEntry] : [],
            'mobilePhone' => $phoneEntry,
            'officePhone' => null,
            'internetAddresses' => $email !== '' ? [
                ['url' => $email, 'name' => 'Email'],
            ] : [],
            'email' => $email !== '' ? $email : null,
            'contactTypes' => [],
            'addresses' => $addressEntry !== null ? [$addressEntry] : [],
        ];

        return $payload;
    }

    protected static function buildFlexPhoneEntry(string $mobile, ?Country $country): ?array
    {
        $dialNumber = preg_replace('/\D+/', '', $mobile) ?? '';
        if ($dialNumber === '') {
            return null;
        }

        $isoCode = trim((string) ($country?->iso_code ?? ''));
        $countryCode = trim((string) ($country?->phone_code ?? ''));
        $countryCode = ltrim($countryCode, '+');

        return [
            'dialNumber' => $dialNumber,
            'isoCode' => $isoCode !== '' ? $isoCode : '',
            'countryCode' => $countryCode !== '' ? $countryCode : '',
            'name' => 'Mobile Phone',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function buildFlexContactAddress(Company $company): ?array
    {
        $company->loadMissing(['country', 'state', 'city']);

        $line1 = trim((string) ($company->address_line_1 ?? ''));
        $line2 = trim((string) ($company->address_line_2 ?? ''));

        // Resolve names via company relations (city_id → cities, state_id → states_provinces).
        $cityName = trim((string) ($company->city?->name ?? ''));
        $stateName = trim((string) ($company->state?->name ?? ''));

        // Flex expects ISO country code (e.g. US), not the country display name.
        $countryCode = strtoupper(trim((string) ($company->country?->iso_code ?? '')));

        $postalCode = trim((string) ($company->postal_code ?? ''));

        if (
            $line1 === ''
            && $line2 === ''
            && $cityName === ''
            && $stateName === ''
            && $postalCode === ''
            && $countryCode === ''
        ) {
            return null;
        }

        return [
            'name' => 'Work',
            'line1' => $line1,
            'line2' => $line2,
            'city' => $cityName,
            'stateOrProvince' => $stateName,
            'postalCode' => $postalCode,
            'country' => $countryCode,
        ];
    }

    protected static function resolveRequesterDisplayName(User $requester, ?UserProfile $profile): string
    {
        if ($profile !== null) {
            $fromProfile = trim((string) $profile->full_name);
            if ($fromProfile !== '') {
                return $fromProfile;
            }
        }

        return trim((string) ($requester->username ?? $requester->name ?? ''));
    }

    /**
     * One-shot checklist logged to flex_integration_logs + flex-integration.log before client/quote calls.
     */
    public function logPreFlightDiagnostics(int $rentalRequestId): void
    {
        $this->rentalRequestId = $rentalRequestId;

        if ($this->flexLogger === null) {
            FlexIntegrationDebugLog::warning($rentalRequestId, $this->providerCompanyId, 'DIAGNOSTIC', 'SKIPPED', [
                'reason' => 'FlexIntegrationLogger not attached to FlexIntegrationService',
            ]);

            return;
        }

        FlexIntegrationDebugLog::step(
            $rentalRequestId,
            $this->providerCompanyId,
            'DIAGNOSTIC',
            'STARTED',
            [
                'flex_api_base_url' => $this->baseUrl,
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $rentalRequestId,
            ],
            'Fetch Quote definitionId, element fields, referral source, and Client resource type',
        );

        $settings = $this->quoteSettings();
        $definitionId = null;
        $missingBlockers = [];

        try {
            $definitionId = $this->getSalesQuoteDefinitionId();
        } catch (\Throwable $e) {
            $missingBlockers[] = 'definitionId (GET element-definition/identity name=Quote)';
            Log::error('Flex Integration: Pre-flight definitionId failed', [
                'provider_company_id' => $this->providerCompanyId,
                'rental_request_id' => $rentalRequestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $optionalUnsetInSettings = [];
        foreach ([
            'status_id' => 'statusId',
            'person_responsible_id' => 'personResponsibleId',
            'default_pricing_model_id' => 'defaultPricingModelId',
            'location_id' => 'locationId',
        ] as $key => $label) {
            if (empty($settings[$key])) {
                $optionalUnsetInSettings[] = $label;
            }
        }

        $defaultsFromApi = [];
        $mergedQuoteIds = [
            'status_id' => null,
            'person_responsible_id' => null,
            'default_pricing_model_id' => null,
            'location_id' => null,
        ];
        if ($missingBlockers === []) {
            $defaultsFromApi = $this->fetchQuoteDefaultsFromElementFields();
            $mergedQuoteIds = $this->resolveQuoteFieldIds();
        }

        $quoteIdsStillEmpty = [];
        foreach (['status_id', 'person_responsible_id', 'default_pricing_model_id', 'location_id'] as $k) {
            if (empty($mergedQuoteIds[$k])) {
                $quoteIdsStillEmpty[] = $k;
            }
        }

        $configSummary = [
            'flex_api_base_url' => $this->baseUrl,
            'provider_company_id' => $this->providerCompanyId,
            'rental_request_id' => $rentalRequestId,
            'auth_header_mode' => config('flex.auth_header'),
            'selected_definition_id' => $definitionId,
            'missing_blocker_settings' => $missingBlockers,
            'optional_quote_fields_not_in_env' => $optionalUnsetInSettings,
            'use_element_fields_api' => (bool) config('flex.use_element_fields_api', true),
            'element_fields_path_pattern' => config('flex.element_fields_path_pattern'),
            'defaults_from_element_fields_api' => $defaultsFromApi,
            'merged_quote_field_ids_for_payload' => $mergedQuoteIds,
            'quote_field_ids_still_empty_after_merge' => $quoteIdsStillEmpty,
            'definition_id_set' => !empty($definitionId),
            'status_id_set' => !empty($settings['status_id']),
            'person_responsible_id_set' => !empty($settings['person_responsible_id']),
            'location_id_set' => !empty($settings['location_id']),
            'default_pricing_model_id_set' => !empty($settings['default_pricing_model_id']),
            'include_currency_in_quote' => (bool) config('flex.include_currency_in_quote', false),
            'resource_type_path' => config('flex.resource_type_path'),
            'resource_type_query' => config('flex.resource_type_query'),
        ];

        $this->logIntegration(
            FlexIntegrationLog::ACTION_DIAGNOSTIC,
            $missingBlockers === [] ? FlexIntegrationLog::STATUS_SUCCESS : FlexIntegrationLog::STATUS_FAILED,
            null,
            $configSummary,
            null,
            $missingBlockers === []
                ? 'Quote definitionId from element-definition/identity; env overrides optional field IDs from GET element/{definitionId}/fields'
                : 'BLOCKER: Quote definitionId missing — GET element-definition/identity must include name "Quote"',
        );

        $referralId = $this->getProSubrentalReferralSourceId();
        if (!$referralId) {
            $snap = $this->fetchReferralSourcesDebugSnapshot();
            $this->logIntegration(
                FlexIntegrationLog::ACTION_DIAGNOSTIC,
                FlexIntegrationLog::STATUS_FAILED,
                $snap['request_url'] ?? null,
                ['expected_referral_name' => 'Pro Subrental Marketplace'],
                $snap,
                'BLOCKER: referralSourceId not resolved — compare expected_referral_name with referral_source_names_from_api',
            );
        } else {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_DIAGNOSTIC,
                FlexIntegrationLog::STATUS_SUCCESS,
                null,
                ['check' => 'referral_source', 'referral_source_id' => $referralId],
                null,
                'Referral source "Pro Subrental Marketplace" resolved',
            );
        }

        $clientRt = $this->getClientResourceTypeId();
        $rtPath = config('flex.resource_type_path', '/f5/api/resource-type/nodes');
        $rtQuery = http_build_query(array_filter(
            config('flex.resource_type_query', ['classname' => 'resource-type', 'nodeId' => 'root']),
            static fn ($v) => $v !== null && $v !== '',
        ));
        $rtUrl = $this->baseUrl . '/' . ltrim($rtPath, '/') . ($rtQuery !== '' ? '?' . $rtQuery : '');
        if (!$clientRt) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_DIAGNOSTIC,
                FlexIntegrationLog::STATUS_FAILED,
                $rtUrl,
                config('flex.resource_type_query'),
                null,
                'BLOCKER: Flex resource type named "Client" not found — verify GET resource-type/nodes?classname=resource-type&nodeId=root on this Flex instance',
            );
        } else {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_DIAGNOSTIC,
                FlexIntegrationLog::STATUS_SUCCESS,
                $rtUrl,
                ['check' => 'client_resource_type'],
                ['client_resource_type_id' => $clientRt],
                'Client resource type id resolved',
            );
        }
    }

    /**
     * @return array{request_url?: string, http_status?: int, response_ok?: bool, referral_source_names_from_api?: array, raw_json_preview?: string, error?: string}
     */
    protected function fetchReferralSourcesDebugSnapshot(): array
    {
        $path = config('flex.referral_source_path', '/f5/api/referral-source/identity');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        try {
            $response = $this->flexHttp(
                'GET',
                $url,
                null,
                FlexIntegrationLog::ACTION_DIAGNOSTIC,
                'Compare referral source names and resolve Pro Subrental Marketplace id',
            );

            $body = $response->json();
            $items = self::normalizeFlexItemArray($body);
            $names = [];
            foreach ($items as $item) {
                if (is_array($item) && isset($item['name'])) {
                    $names[] = trim((string) $item['name']);
                }
            }

            return [
                'request_url' => $url,
                'http_status' => $response->status(),
                'response_ok' => $response->successful(),
                'referral_source_names_from_api' => $names,
                'raw_json_preview' => self::truncateHttpBody($response->body()),
            ];
        } catch (\Throwable $e) {
            return [
                'request_url' => $url,
                'error' => $e->getMessage(),
                'referral_source_names_from_api' => [],
            ];
        }
    }

    protected static function truncateHttpBody(string $body, ?int $maxChars = null): string
    {
        $maxChars ??= (int) config('flex.log_response_preview_max', 8000);
        if ($maxChars < 200) {
            $maxChars = 8000;
        }

        if (strlen($body) <= $maxChars) {
            return $body;
        }

        return substr($body, 0, $maxChars) . '… [truncated, total ' . strlen($body) . ' bytes]';
    }

    public static function productDisplayName(Product $product): string
    {
        $product->loadMissing('brand');
        $brand = $product->brand->name ?? '';

        return trim($brand . ' ' . ($product->model ?? ''));
    }

    public static function persistFlexResourceOnInventory(int $providerCompanyId, int $productId, string $flexResourceId): void
    {
        $updated = DB::transaction(function () use ($providerCompanyId, $productId, $flexResourceId) {
            return Equipment::query()
                ->where('company_id', $providerCompanyId)
                ->where('product_id', $productId)
                ->update(['flex_resource_id' => $flexResourceId]);
        });

        Log::info('Flex Integration: Database update flex_resource_id', [
            'provider_company_id' => $providerCompanyId,
            'product_id' => $productId,
            'flex_resource_id' => $flexResourceId,
            'rows_updated' => $updated,
        ]);

        if ($updated === 0) {
            Log::warning('Flex Integration: No company_inventory row to update with flex_resource_id', [
                'provider_company_id' => $providerCompanyId,
                'product_id' => $productId,
                'flex_resource_id' => $flexResourceId,
            ]);
        }
    }

    protected function logPersistResourceId(int $productId, string $flexResourceId, string $source): void
    {
        $this->logIntegration(
            FlexIntegrationLog::ACTION_PERSIST_RESOURCE_ID,
            FlexIntegrationLog::STATUS_SUCCESS,
            null,
            [
                'product_id' => $productId,
                'flex_resource_id' => $flexResourceId,
                'source' => $source,
                'table' => 'company_inventory',
            ],
            null,
            null,
            null,
            $flexResourceId,
        );

        FlexIntegrationDebugLog::step(
            $this->rentalRequestId ?? 0,
            $this->providerCompanyId,
            'DATABASE_UPDATE',
            'FLEX_RESOURCE_ID',
            [
                'product_id' => $productId,
                'flex_resource_id' => $flexResourceId,
                'source' => $source,
            ],
            'Attach product to sales quote',
        );
    }
}
