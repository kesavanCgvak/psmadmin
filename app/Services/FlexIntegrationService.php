<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Equipment;
use App\Models\FlexIntegrationLog;
use App\Models\Product;
use App\Models\RentalJob;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\FlexIntegrationDebugLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
     * GET element definition fields (cached). Non-empty env / company_integrations.settings always override these.
     */
    protected function fetchQuoteDefaultsFromElementFields(): array
    {
        if (!config('flex.use_element_fields_api', true)) {
            return [];
        }

        $definitionId = $this->quoteSettings()['sales_quote_definition_id'] ?? null;
        if (empty($definitionId)) {
            return [];
        }

        $cacheKey = 'flex_quote_elem_fields_' . $this->providerCompanyId . '_' . $definitionId;
        $ttl = (int) config('flex.element_fields_cache_ttl', 3600);

        return Cache::remember($cacheKey, $ttl, function () use ($definitionId) {
            $pattern = config('flex.element_fields_path_pattern', '/f5/api/element/%s/fields');
            $path = sprintf($pattern, $definitionId);
            $url = $this->baseUrl . '/' . ltrim($path, '/');

            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
                    ->get($url, [
                        'elementId' => '',
                        'parentElementId' => '',
                    ]);

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
            $response = Http::timeout($this->timeout)
                ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
                ->get($url);

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

        $firstName = trim((string) ($profile->first_name ?? ''));
        $lastName = trim((string) ($profile->last_name ?? ''));
        $email = trim((string) ($profile->email ?? $requester->email ?? ''));
        $mobile = trim((string) ($profile->mobile ?? ''));

        if ($email !== '') {
            $found = $this->searchContact($email);
            if ($found !== null) {
                Log::info('Flex Integration: Client Found', [
                    'provider_company_id' => $this->providerCompanyId,
                    'client_id' => $found,
                ]);

                return $found;
            }
        }

        $nameSearch = trim($firstName . ' ' . $lastName);
        if ($nameSearch !== '') {
            $found = $this->searchContact($nameSearch);
            if ($found !== null) {
                Log::info('Flex Integration: Client Found', [
                    'provider_company_id' => $this->providerCompanyId,
                    'client_id' => $found,
                ]);

                return $found;
            }
        }

        $clientTypeId = $this->getClientResourceTypeId();
        if (!$clientTypeId) {
            throw new \RuntimeException('Flex Client resource type id not found (GET /f5/api/resource-type, name = Client).');
        }

        $payload = array_filter([
            'salutation' => '',
            'firstName' => $firstName !== '' ? $firstName : ($requester->username ?? 'Customer'),
            'lastName' => $lastName !== '' ? $lastName : '—',
            'organization' => false,
            'email' => $email !== '' ? $email : null,
            'mobilePhone' => $mobile !== '' ? $mobile : null,
            'resourceTypes' => [['id' => $clientTypeId]],
        ], fn ($v) => $v !== null && $v !== []);

        $path = config('flex.contact_create_path', '/f5/api/contact');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $this->logIntegration(
            FlexIntegrationLog::ACTION_CREATE_CLIENT,
            FlexIntegrationLog::STATUS_PROCESSING,
            $url,
            $payload,
            null,
            'POST create Flex contact (outbound payload)',
        );

        $response = Http::timeout($this->timeout)
            ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
            ->post($url, $payload);

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

        return (string) $id;
    }

    public function createSalesQuote(RentalJob $rentalJob, string $clientId): array
    {
        $settings = $this->quoteSettings();
        if (empty($settings['sales_quote_definition_id'])) {
            throw new \RuntimeException('Flex sales quote definition id is not configured (env FLEX_SALES_QUOTE_DEFINITION_ID or company_integrations.settings).');
        }

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
            'definitionId' => $settings['sales_quote_definition_id'],
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

        $path = config('flex.element_create_path', '/f5/api/element');
        $url = rtrim($this->baseUrl . '/' . ltrim($path, '/'), '/') . '/';

        $this->logIntegration(
            FlexIntegrationLog::ACTION_CREATE_QUOTE,
            FlexIntegrationLog::STATUS_PROCESSING,
            $url,
            $payload,
            null,
            'POST create sales quote (outbound payload)',
        );

        $response = Http::timeout($this->timeout)
            ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
            ->post($url, $payload);

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
            'element_id' => $elementIdStr,
            'element_number' => $elementNumber,
        ]);

        return [
            'id' => $elementIdStr,
            'number' => $elementNumber !== null && $elementNumber !== '' ? (string) $elementNumber : null,
        ];
    }

    public function searchFlexProduct(string $searchText): ?string
    {
        $path = config('flex.global_search_path', '/f5/api/search');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $params = [
            'searchTypes' => 'inventory-model',
            'searchText' => $searchText,
            'page' => 0,
            'size' => 20,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
                ->get($url, $params);

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

                return null;
            }

            $items = $data['content'] ?? $data['results'] ?? [];
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
                    'search_text' => $searchText,
                ]);

                return null;
            }

            $first = $items[0];
            $id = is_array($first) ? ($first['id'] ?? null) : null;
            if ($id === null || $id === '') {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_PRODUCT_NOT_FOUND,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    $params,
                    is_array($data) ? $data : null,
                    'First hit missing id',
                );

                return null;
            }

            $idStr = (string) $id;

            $this->logIntegration(
                FlexIntegrationLog::ACTION_SEARCH_PRODUCT,
                FlexIntegrationLog::STATUS_SUCCESS,
                $url,
                $params,
                is_array($first) ? $first : null,
                null,
                null,
                $idStr,
            );

            Log::info('Flex Integration: Product Found', [
                'provider_company_id' => $this->providerCompanyId,
                'flex_resource_id' => $idStr,
                'search_text' => $searchText,
            ]);

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

    /**
     * @return array{flex_product_id: ?string, response: array|null}
     */
    public function attachProductToSalesQuote(string $salesQuoteId, string $resourceId, int $quantity): array
    {
        $base = rtrim($this->baseUrl . '/' . ltrim(config('flex.financial_line_item_path', '/f5/api/financial-document-line-item'), '/'), '/');
        $url = $base . '/' . $salesQuoteId . '/add-resource/' . $resourceId;

        $requestPayload = [
            'resourceParentId ' => '',
            'managedResourceLineItemType' => 'inventory-model',
            'quantity' => $quantity,
            'parentLineItemId' => '',
            'nextSiblingId' => '',

        ];

        $this->logIntegration(
            FlexIntegrationLog::ACTION_ADD_PRODUCT_TO_QUOTE,
            FlexIntegrationLog::STATUS_PROCESSING,
            $url,
            $requestPayload,
            null,
            'POST add line item (outbound payload)',
            $salesQuoteId,
            $resourceId,
        );

        $response = Http::timeout($this->timeout)
            ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
            ->post($url, $requestPayload);

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

        $lineId = $data['id'] ?? $data['lineItemId'] ?? $data['financialDocumentLineItemId'] ?? null;
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
            'sales_quote_id' => $salesQuoteId,
            'resource_id' => $resourceId,
            'line_id' => $lineIdStr,
            'quantity' => $quantity,
        ]);

        return [
            'flex_product_id' => $lineIdStr,
            'response' => is_array($data) ? $data : null,
        ];
    }

    public function trackFinDocQuickLineAdded(): void
    {
        $path = config('flex.user_event_tracking_path', '/f5/api/user-event-tracking');
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $payload = ['eventType' => 'fin-doc-quick-line-added'];

        try {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_TRACK_EVENT,
                FlexIntegrationLog::STATUS_PROCESSING,
                $url,
                $payload,
                null,
                'POST user-event-tracking (outbound payload)',
            );

            $response = Http::timeout($this->timeout)
                ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
                ->post($url, $payload);

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
        $s = $this->integration->settings ?? [];

        return [
            'sales_quote_definition_id' => $s['sales_quote_definition_id'] ?? $s['definitionId'] ?? config('flex.sales_quote_definition_id'),
            'currency_id' => $s['currency_id'] ?? $s['currencyId'] ?? null,
            'status_id' => $s['status_id'] ?? $s['statusId'] ?? config('flex.sales_quote_status_id'),
            'person_responsible_id' => $s['person_responsible_id'] ?? $s['personResponsibleId'] ?? config('flex.sales_quote_person_responsible_id'),
            'location_id' => $s['location_id'] ?? $s['locationId'] ?? config('flex.sales_quote_location_id'),
            'default_pricing_model_id' => $s['default_pricing_model_id'] ?? $s['defaultPricingModelId'] ?? config('flex.sales_quote_default_pricing_model_id'),
        ];
    }

    protected function authHeaders(): array
    {
        $authType = config('flex.auth_header', 'bearer');
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
            $response = Http::timeout($this->timeout)
                ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
                ->get($url, $params);

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
        $cacheKey = 'flex_client_resource_type_id_' . $this->providerCompanyId;
        $ttl = (int) config('flex.client_resource_type_cache_ttl', 86400);
        $cached = Cache::get($cacheKey);
        if ($cached !== null && $cached !== '') {
            return (string) $cached;
        }

        $path = config('flex.resource_type_path', '/f5/api/resource-type');
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
                ->get($url);

            $data = $response->json();

            if (!$response->successful()) {
                $this->logIntegration(
                    FlexIntegrationLog::ACTION_API_ERROR,
                    FlexIntegrationLog::STATUS_FAILED,
                    $url,
                    null,
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
                $name = $item['name'] ?? '';
                if (is_string($name) && strcasecmp(trim($name), 'Client') === 0) {
                    $id = $item['id'] ?? null;
                    if ($id !== null && $id !== '') {
                        $idStr = (string) $id;
                        Cache::put($cacheKey, $idStr, $ttl);

                        return $idStr;
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_API_ERROR,
                FlexIntegrationLog::STATUS_FAILED,
                $url,
                null,
                null,
                $e->getMessage(),
            );

            return null;
        }
    }

    /**
     * One-shot checklist logged to flex_integration_logs + flex-integration.log before client/quote calls.
     */
    public function logPreFlightDiagnostics(int $rentalRequestId): void
    {
        if ($this->flexLogger === null) {
            FlexIntegrationDebugLog::warning($rentalRequestId, $this->providerCompanyId, 'DIAGNOSTIC', 'SKIPPED', [
                'reason' => 'FlexIntegrationLogger not attached to FlexIntegrationService',
            ]);

            return;
        }

        $settings = $this->quoteSettings();

        $missingBlockers = [];
        if (empty($settings['sales_quote_definition_id'])) {
            $missingBlockers[] = 'definitionId (FLEX_SALES_QUOTE_DEFINITION_ID or company_integrations.settings)';
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
            'auth_header_mode' => config('flex.auth_header'),
            'missing_blocker_settings' => $missingBlockers,
            'optional_quote_fields_not_in_env_or_settings' => $optionalUnsetInSettings,
            'use_element_fields_api' => (bool) config('flex.use_element_fields_api', true),
            'element_fields_path_pattern' => config('flex.element_fields_path_pattern'),
            'defaults_from_element_fields_api' => $defaultsFromApi,
            'merged_quote_field_ids_for_payload' => $mergedQuoteIds,
            'quote_field_ids_still_empty_after_merge' => $quoteIdsStillEmpty,
            'definition_id_set' => !empty($settings['sales_quote_definition_id']),
            'status_id_set' => !empty($settings['status_id']),
            'person_responsible_id_set' => !empty($settings['person_responsible_id']),
            'location_id_set' => !empty($settings['location_id']),
            'default_pricing_model_id_set' => !empty($settings['default_pricing_model_id']),
            'include_currency_in_quote' => (bool) config('flex.include_currency_in_quote', false),
            'resource_type_path' => config('flex.resource_type_path'),
        ];

        $this->logIntegration(
            FlexIntegrationLog::ACTION_DIAGNOSTIC,
            $missingBlockers === [] ? FlexIntegrationLog::STATUS_SUCCESS : FlexIntegrationLog::STATUS_FAILED,
            null,
            $configSummary,
            null,
            $missingBlockers === []
                ? 'Quote IDs: env/settings override GET element/{definitionId}/fields; see merged_quote_field_ids_for_payload'
                : 'BLOCKER: sales quote definitionId missing — set FLEX_SALES_QUOTE_DEFINITION_ID or company_integrations.settings',
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
        $rtUrl = $this->baseUrl . '/' . ltrim(config('flex.resource_type_path', '/f5/api/resource-type/nodes'), '/');
        if (!$clientRt) {
            $this->logIntegration(
                FlexIntegrationLog::ACTION_DIAGNOSTIC,
                FlexIntegrationLog::STATUS_FAILED,
                $rtUrl,
                null,
                null,
                'BLOCKER: Flex resource type named "Client" not found — verify GET resource-type/nodes (or FLEX_RESOURCE_TYPE_PATH) on this Flex instance',
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
            $response = Http::timeout($this->timeout)
                ->withHeaders(array_merge($this->authHeaders(), ['Content-Type' => 'application/json']))
                ->get($url);

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
        Equipment::query()
            ->where('company_id', $providerCompanyId)
            ->where('product_id', $productId)
            ->update(['flex_resource_id' => $flexResourceId]);
    }
}
