<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\CompanyIntegration;
use App\Models\Equipment;
use App\Models\EquipmentImage;
use App\Models\Product;
use App\Support\ProductNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlexService
{
    protected ?string $baseUrl = null;

    protected ?string $apiKey = null;

    protected int $timeout = 15;

    protected int $companyId;

    /**
     * Create a new FlexService instance for the given company.
     * Loads credentials from company_integrations.
     *
     * @param int $companyId
     * @throws \RuntimeException When integration is missing or invalid
     */
    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;

        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('integration_type', 'flex')
            ->first();

        if (!$integration) {
            throw new \RuntimeException('Flex integration not configured for this company.');
        }

        if (empty($integration->api_base_url) || empty($integration->api_key)) {
            throw new \RuntimeException('Flex API credentials are incomplete.');
        }

        $this->baseUrl = rtrim($integration->api_base_url, '/');
        $this->apiKey = $integration->api_key;
    }

    protected function getAuthHeaders(): array
    {
        $authType = config('flex.auth_header', 'bearer');
        if ($authType === 'x_auth') {
            return ['X-Auth-Token' => $this->apiKey];
        }
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    /**
     * Search Flex inventory by keyword.
     *
     * @param int $companyId Company ID (for credential lookup)
     * @param string $keyword Search term
     * @return array Simplified list: [['flex_id' => ..., 'name' => ..., 'barcode' => ..., 'size' => ...], ...]
     */
    public static function searchInventory(int $companyId, string $keyword): array
    {
        $service = new self($companyId);

        $searchPath = config('flex.search_path', '/f5/api/inventory-model/search');
        $url = $service->baseUrl . $searchPath;
        $params = [
            'searchText' => $keyword,
            'serializedOnly' => false,
            'page' => 0,
            'size' => 20,
        ];

        Log::debug('Flex search request', [
            'url' => $url,
            'method' => 'GET',
            'params' => $params,
        ]);

        try {
            $response = Http::timeout($service->timeout)
                ->withHeaders(array_merge(
                    $service->getAuthHeaders(),
                    ['Content-Type' => 'application/json']
                ))
                ->get($url, $params);

            Log::debug('Flex search response', [
                'url' => $url,
                'status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 500),
            ]);

            if (!$response->successful()) {
                Log::warning('Flex search API error', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                $message = $response->json('message') ?? (string) $response->status();
                if ($response->status() === 404) {
                    $message .= ' — Check config/flex.php: the search path may be wrong for your Flex instance.';
                }
                throw new \RuntimeException('Flex API returned an error: ' . $message);
            }

            $data = $response->json();
            $content = $data['content'] ?? [];

            return array_map(function ($item) {
                return [
                    'flex_id' => $item['id'] ?? null,
                    'name' => $item['name'] ?? '',
                    'barcode' => $item['barcode'] ?? '',
                    'size' => $item['size'] ?? '',
                ];
            }, $content);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Flex API timeout', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Flex API request timed out.');
        }
    }

    /**
     * Get detailed inventory item from Flex.
     *
     * @param int $companyId Company ID (for credential lookup)
     * @param string|int $flexId Flex inventory model ID
     * @return array Simplified structure with: name, height, width, modelLength, weight,
     *               replacementCost, sku, partNumber, linearUnit, weightUnit, imageUrl
     */
    public static function getInventoryDetails(int $companyId, $flexId): array
    {
        $service = new self($companyId);

        $detailsPath = rtrim(config('flex.details_path', '/f5/api/inventory-model'), '/');
        $url = $service->baseUrl . $detailsPath . '/' . $flexId;

        Log::debug('Flex details request', [
            'url' => $url,
            'method' => 'GET',
            'flex_id' => $flexId,
        ]);

        try {
            $response = Http::timeout($service->timeout)
                ->withHeaders(array_merge(
                    $service->getAuthHeaders(),
                    ['Content-Type' => 'application/json']
                ))
                ->get($url);

            Log::debug('Flex details response', [
                'url' => $url,
                'status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 500),
            ]);

            if (!$response->successful()) {
                Log::warning('Flex details API error', [
                    'url' => $url,
                    'flex_id' => $flexId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                $message = $response->json('message') ?? (string) $response->status();
                if ($response->status() === 404) {
                    $message .= ' — Check config/flex.php: the details path may be wrong for your Flex instance.';
                }
                throw new \RuntimeException('Flex API returned an error: ' . $message);
            }

            $data = $response->json();

            $ref = $data['referenceData'] ?? [];
            $linearUnit = $ref['linearUnit']['name'] ?? null;
            $weightUnit = $ref['weightUnit']['name'] ?? null;

            // Collect image URLs: single imageUrl and/or images array
            $imageUrls = [];
            if (!empty($ref['imageUrl'])) {
                $imageUrls[] = $ref['imageUrl'];
            }
            if (!empty($ref['images']) && is_array($ref['images'])) {
                foreach ($ref['images'] as $img) {
                    $url = is_string($img) ? $img : ($img['url'] ?? $img['imageUrl'] ?? null);
                    if ($url) {
                        $imageUrls[] = $url;
                    }
                }
            }

            return [
                'name' => $data['name'] ?? '',
                'height' => $data['height'] ?? null,
                'width' => $data['width'] ?? null,
                'modelLength' => $data['modelLength'] ?? null,
                'weight' => $data['weight'] ?? null,
                'replacementCost' => $data['replacementCost'] ?? null,
                'sku' => $data['sku'] ?? null,
                'partNumber' => $data['partNumber'] ?? null,
                'linearUnit' => $linearUnit,
                'weightUnit' => $weightUnit,
                'imageUrls' => array_values(array_unique(array_filter($imageUrls))),
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Flex API timeout', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Flex API request timed out.');
        }
    }

    /**
     * Get Rental resource type qty on hand / allocated from Flex qty-per-location API.
     * GET /f5/api/inventory-model/qty-per-location?modelId={flexId}
     * Sums qtyOnHand and qtyAllocated for resourceType.name === "Rental" across all locations.
     *
     * @return array{qty_on_hand: int|null, qty_allocated: int|null}
     */
    public static function getRentalQtySummary(int $companyId, string $flexId): array
    {
        $defaults = ['qty_on_hand' => null, 'qty_allocated' => null];

        try {
            $service = new self($companyId);
            $path = config('flex.qty_per_location_path', '/f5/api/inventory-model/qty-per-location');
            $url = $service->baseUrl . $path;
            $params = ['modelId' => $flexId];

            $response = Http::timeout($service->timeout)
                ->withHeaders(array_merge(
                    $service->getAuthHeaders(),
                    ['Content-Type' => 'application/json']
                ))
                ->get($url, $params);

            if (!$response->successful()) {
                Log::debug('Flex qty-per-location API non-success', [
                    'status' => $response->status(),
                    'body_preview' => substr($response->body(), 0, 300),
                ]);
                return $defaults;
            }

            $rows = $response->json();
            if (!is_array($rows)) {
                return $defaults;
            }

            $sumOnHand = 0;
            $sumAllocated = 0;
            $foundRental = false;

            foreach ($rows as $row) {
                $stockList = $row['stockQtyList'] ?? [];
                if (!is_array($stockList)) {
                    continue;
                }
                foreach ($stockList as $item) {
                    $typeName = $item['resourceType']['name'] ?? '';
                    if (strcasecmp(trim((string) $typeName), 'Rental') !== 0) {
                        continue;
                    }
                    $foundRental = true;
                    $sumOnHand += (int) ($item['qtyOnHand'] ?? 0);
                    $sumAllocated += (int) ($item['qtyAllocated'] ?? 0);
                }
            }

            if (!$foundRental) {
                return $defaults;
            }

            return [
                'qty_on_hand' => $sumOnHand,
                'qty_allocated' => $sumAllocated,
            ];
        } catch (\Throwable $e) {
            Log::debug('Flex qty-per-location fetch failed', ['error' => $e->getMessage()]);
            return $defaults;
        }
    }

    /**
     * Build a compact payload for import-check / UI from getInventoryDetails + getRentalQtySummary.
     *
     * @param array $details Output of getInventoryDetails
     * @param array $rentalQty Output of getRentalQtySummary
     * @return array{
     *   name: string|null,
     *   sku: string|null,
     *   part_number: string|null,
     *   rental_qty_on_hand: int|null,
     *   rental_qty_allocated: int|null,
     *   linear_unit: string|null,
     *   weight_unit: string|null,
     *   height: mixed,
     *   width: mixed,
     *   modelLength: mixed,
     *   weight: mixed
     * }
     */
    public static function flexImportCheckFlexPayload(array $details, array $rentalQty): array
    {
        return [
            'name' => $details['name'] ?? null,
            'sku' => $details['sku'] ?? null,
            'part_number' => $details['partNumber'] ?? null,
            'rental_qty_on_hand' => $rentalQty['qty_on_hand'] ?? null,
            'rental_qty_allocated' => $rentalQty['qty_allocated'] ?? null,
            'linear_unit' => $details['linearUnit'] ?? null,
            'weight_unit' => $details['weightUnit'] ?? null,
            'height' => $details['height'] ?? null,
            'width' => $details['width'] ?? null,
            'modelLength' => $details['modelLength'] ?? null,
            'weight' => $details['weight'] ?? null,
        ];
    }

    /**
     * Get USD currency ID from Flex (instance method).
     * API: GET /f5/api/currency/identity — find currency where isoCode = 'USD'.
     *
     * @return string|null Currency ID or null if not found/API failure
     */
    public function getCurrencyId(): ?string
    {
        return self::getUsdCurrencyId($this->companyId);
    }

    /**
     * Get Day Rate (retailPricePerUnit) for a Flex resource.
     * API: GET /f5/api/resource-pricing/grid-node with resourceId and currencyId.
     * Finds item where pricingModelName === "Day Rate".
     *
     * @param string|int $flexId Flex resource/inventory model ID
     * @param string|int $currencyId Currency ID from getCurrencyId()
     * @return float|null Day rate or null if not found/API failure
     */
    public function getDayRate($flexId, $currencyId): ?float
    {
        if (!$currencyId) {
            Log::warning('Flex Day Rate: currencyId is null, cannot fetch pricing');
            return null;
        }

        try {
            $path = config('flex.pricing_path', '/f5/api/resource-pricing/grid-node');
            $url = $this->baseUrl . $path;
            $params = [
                'resourceId' => $flexId,
                'currencyId' => $currencyId,
            ];

            $response = Http::timeout($this->timeout)
                ->withHeaders(array_merge(
                    $this->getAuthHeaders(),
                    ['Content-Type' => 'application/json']
                ))
                ->get($url, $params);

            $pricingResponse = $response->json();
            Log::info('Flex Pricing Response', [
                'flex_id' => $flexId,
                'response' => $pricingResponse,
            ]);

            if (!$response->successful()) {
                Log::warning('Flex pricing API error', [
                    'url' => $url,
                    'resource_id' => $flexId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $rows = $pricingResponse['content'] ?? $pricingResponse['rows'] ?? $pricingResponse['data'] ?? (is_array($pricingResponse) ? $pricingResponse : []);

            if (!is_array($rows)) {
                Log::warning('Day Rate not found', [
                    'flex_id' => $flexId,
                    'pricing_response' => $pricingResponse,
                ]);
                return null;
            }

            $dayRate = null;
            foreach ($rows as $price) {
                if (
                    isset($price['pricingModelName']) &&
                    trim(strtolower((string) $price['pricingModelName'])) === 'day rate'
                ) {
                    $dayRate = $price['retailPricePerUnit'] ?? $price['retail_price_per_unit'] ?? $price['price'] ?? null;
                    break;
                }
            }

            $dayRate = $dayRate !== null && $dayRate !== '' ? (float) $dayRate : null;

            if ($dayRate === null) {
                Log::warning('Day Rate not found', [
                    'flex_id' => $flexId,
                    'pricing_response' => $pricingResponse,
                ]);
            }

            return $dayRate;
        } catch (\Exception $e) {
            Log::error('Flex Day Rate fetch error', [
                'resource_id' => $flexId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get detailed inventory item from Flex (instance method).
     *
     * @param string|int $flexId Flex inventory model ID
     * @return array Simplified structure with: name, height, width, modelLength, weight,
     *               replacementCost, sku, partNumber, linearUnit, weightUnit, imageUrl
     */
    public function fetchInventoryDetails($flexId): array
    {
        return self::getInventoryDetails($this->companyId, $flexId);
    }

    /**
     * Get USD currency ID from Flex. Cached for 24 hours.
     *
     * @param int $companyId Company ID (for credential lookup)
     * @return string|null Currency ID or null if not found/API failure
     */
    public static function getUsdCurrencyId(int $companyId): ?string
    {
        $cacheKey = 'flex_usd_currency_id_' . $companyId;

        return Cache::remember($cacheKey, 86400, function () use ($companyId) {
            try {
                $service = new self($companyId);
                $path = config('flex.currency_path', '/f5/api/currency/identity');
                $url = $service->baseUrl . $path;

                $response = Http::timeout($service->timeout)
                    ->withHeaders(array_merge(
                        $service->getAuthHeaders(),
                        ['Content-Type' => 'application/json']
                    ))
                    ->get($url);

                if (!$response->successful()) {
                    Log::warning('Flex currency API error', [
                        'url' => $url,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    return null;
                }

                $data = $response->json();

                // Flex API can return: wrapped {content/currencies/data: [...]} OR direct array [{...}]
                $items = $data['content'] ?? $data['currencies'] ?? $data['data'] ?? null;
                if ($items === null && is_array($data) && isset($data[0]) && is_array($data[0])) {
                    $items = $data;
                }

                if (is_array($items)) {
                    $usd = collect($items)->first(function ($item) {
                        $isoCode = $item['isoCode'] ?? null;
                        return strtoupper((string) $isoCode) === 'USD';
                    });
                    if ($usd !== null) {
                        return (string) ($usd['id'] ?? $usd['currencyId'] ?? null);
                    }
                }

                // Single currency object (not wrapped in array)
                if (is_array($data) && isset($data['isoCode'])) {
                    if (strtoupper((string) $data['isoCode']) === 'USD') {
                        return (string) ($data['id'] ?? null);
                    }
                }

                Log::warning('Flex currency: USD not found in currency/identity response');
                return null;
            } catch (\Exception $e) {
                Log::error('Flex currency fetch error', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Get Day Rate (retailPricePerUnit) for a Flex resource.
     *
     * @param int $companyId Company ID (for credential lookup)
     * @param string|int $resourceId Flex resource/inventory model ID
     * @return float|null Day rate or null if not found/API failure
     */
    public static function getDayRentalRate(int $companyId, $resourceId): ?float
    {
        try {
            $currencyId = self::getUsdCurrencyId($companyId);

            if (!$currencyId) {
                Log::warning('Flex Day Rate: USD currency not found (currencyId is null), stopping pricing fetch');
                return null;
            }

            $service = new self($companyId);
            return $service->getDayRate($resourceId, $currencyId);
        } catch (\Exception $e) {
            Log::error('Flex Day Rate fetch error', [
                'resource_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse Flex product name into brand + model using DB brands.
     * Matches first word(s) of Flex name with brand name.
     *
     * @param string $flexName Flex product name (e.g. "Apogee AE-5")
     * @return array{brand_id: int|null, model: string, normalized_model: string|null}
     */
    public static function parseBrandAndModel(string $flexName): array
    {
        $flexName = trim($flexName);
        if ($flexName === '') {
            return ['brand_id' => null, 'model' => $flexName, 'normalized_model' => null];
        }

        $brands = Brand::orderByRaw('LENGTH(name) DESC')->get();
        $flexNameLower = strtolower($flexName);

        foreach ($brands as $brand) {
            $brandName = trim($brand->name ?? '');
            if ($brandName === '') {
                continue;
            }
            $brandNameLower = strtolower($brandName);
            if (str_starts_with($flexNameLower, $brandNameLower)) {
                $model = trim(substr($flexName, strlen($brandName)));
                $normalizedModel = ProductNormalizer::normalizeCode($model);
                return [
                    'brand_id' => $brand->id,
                    'model' => $model ?: $flexName,
                    'normalized_model' => $normalizedModel,
                ];
            }
        }

        $model = $flexName;
        $normalizedModel = ProductNormalizer::normalizeCode($model);
        return ['brand_id' => null, 'model' => $model, 'normalized_model' => $normalizedModel];
    }

    /**
     * Sync existing product with Flex data: update inventory_master and create company_inventory.
     * Fetches Flex details + Day Rate, updates product dimensions/replacement_price if available,
     * creates company_inventory with rental_rate and replacement_price.
     *
     * @param int $companyId Company ID
     * @param int $productId inventory_master (Product) ID
     * @param string $flexId Flex resource ID
     * @param string|null $softwareCode SKU/part number
     * @param int $quantity Quantity to import
     * @param int $userId User performing import
     * @param array $imageUrls Flex image URLs
     * @param float|null $overrideRentalRate Optional override (e.g. from request)
     * @return Equipment Created company_inventory record
     * @throws \RuntimeException On API/validation errors
     */
    public static function syncExistingProductWithFlexData(
        int $companyId,
        int $productId,
        string $flexId,
        ?string $softwareCode,
        int $quantity,
        int $userId,
        array $imageUrls = [],
        ?float $overrideRentalRate = null
    ): Equipment {
        $service = new self($companyId);
        $product = Product::findOrFail($productId);

        // 1. Fetch Flex details (log error but continue if API fails)
        $details = [];
        try {
            $details = $service->fetchInventoryDetails($flexId);
        } catch (\Exception $e) {
            Log::warning('Flex sync: getInventoryDetails failed, continuing with partial data', [
                'flex_id' => $flexId,
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Fetch pricing (payload override wins over Flex Day Rate)
        $dayRate = $overrideRentalRate;
        if ($dayRate === null) {
            $currencyId = $service->getCurrencyId();
            $dayRate = $currencyId ? $service->getDayRate($flexId, $currencyId) : null;
        }

        // 3. Update inventory_master ONLY if values are empty (do not overwrite existing)
        $productUpdates = [];
        $dimensionFields = ['height', 'width', 'length', 'weight', 'replacement_price'];
        $flexMapping = [
            'height' => 'height',
            'width' => 'width',
            'length' => 'modelLength',
            'weight' => 'weight',
            'replacement_price' => 'replacementCost',
        ];

        foreach ($dimensionFields as $dbField) {
            $currentValue = $product->{$dbField};
            if ($currentValue !== null && $currentValue !== '') {
                continue;
            }
            $flexKey = $flexMapping[$dbField];
            $value = $details[$flexKey] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($dbField === 'replacement_price' && (float) $value === 0.0) {
                continue;
            }
            $productUpdates[$dbField] = $dbField === 'replacement_price' ? (float) $value : $value;
        }

        if (!empty($productUpdates)) {
            $product->update($productUpdates);
        }

        // 4. Resolve replacement cost for company_inventory (0 = allow but prefer existing)
        $replacementCost = $details['replacementCost'] ?? null;
        $replacementPrice = null;
        if ($replacementCost !== null && $replacementCost !== '') {
            $replacementPrice = (float) $replacementCost;
        }

        // 5. Create company_inventory (rental_rate = null if Day Rate not found)
        $equipment = Equipment::create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'flex_resource_id' => $flexId,
            'software_code' => $softwareCode ?? $flexId,
            'quantity' => $quantity,
            'rental_price' => $dayRate,
            'replacement_price' => $replacementPrice,
        ]);

        // 6. Create equipment images
        foreach ($imageUrls as $url) {
            $url = trim($url);
            if (empty($url)) {
                continue;
            }
            EquipmentImage::create([
                'equipment_id' => $equipment->id,
                'image_path' => $url,
            ]);
        }

        return $equipment;
    }

    /**
     * Minimum length for substring/LIKE matching on normalized_model.
     * Shorter values (e.g. "ss") must not match inside longer Flex names (e.g. "debbiessubaru").
     * Codes that include a digit (e.g. "ae3" from "AE-3") are allowed at 3 chars — they rarely
     * produce the same false positives as 3-letter-only fragments.
     */
    protected const MIN_NORMALIZED_SUBSTRING_MATCH_LEN = 4;

    protected const MIN_NORMALIZED_SUBSTRING_MATCH_LEN_WITH_DIGIT = 3;

    /**
     * Compare leading characters of two normalized model strings (Flex vs inventory_master).
     * Catches cases like Flex "ae3smallspeaker" vs PSM "ae3s2loudspeaker" where neither
     * full string is a substring of the other but the model root matches.
     */
    protected static function minPrefixMatchLength(string $normalized): int
    {
        $len = strlen($normalized);
        if ($len >= 4) {
            return 4;
        }
        if ($len >= 3 && preg_match('/\d/', $normalized)) {
            return 3;
        }

        return 0;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $q
     */
    protected static function applyPrefixMatchOnNormalizedModel($q, ?string $normalizedModel): void
    {
        if ($normalizedModel === null || $normalizedModel === '') {
            return;
        }

        $prefixLen = self::minPrefixMatchLength($normalizedModel);
        if ($prefixLen < 3) {
            return;
        }

        $prefix = substr($normalizedModel, 0, $prefixLen);
        $q->orWhere(function ($sub) use ($prefix, $prefixLen) {
            $sub->whereRaw('LENGTH(COALESCE(normalized_model, \'\')) >= ?', [$prefixLen])
                ->whereRaw('LEFT(COALESCE(normalized_model, \'\'), ?) = ?', [$prefixLen, $prefix]);
        });
    }

    /**
     * Effective minimum length for LIKE-based substring matching for this token.
     */
    protected static function effectiveSubstringMinLen(string $normalizedModel): int
    {
        if (strlen($normalizedModel) >= self::MIN_NORMALIZED_SUBSTRING_MATCH_LEN) {
            return self::MIN_NORMALIZED_SUBSTRING_MATCH_LEN;
        }
        if (
            strlen($normalizedModel) >= self::MIN_NORMALIZED_SUBSTRING_MATCH_LEN_WITH_DIGIT
            && preg_match('/\d/', $normalizedModel)
        ) {
            return self::MIN_NORMALIZED_SUBSTRING_MATCH_LEN_WITH_DIGIT;
        }

        return self::MIN_NORMALIZED_SUBSTRING_MATCH_LEN;
    }

    /**
     * Find existing product in inventory_master by brand_id + normalized model.
     * Uses exact match first, then fallback to partial matching (e.g. "NXAE104" matches "NXAE104 AES/EBU Network Card").
     *
     * @param int|null $brandId Brand ID
     * @param string|null $normalizedModel Normalized model string from full Flex name
     * @param string|null $rawModelOrFullName Optional raw Flex name for extractModelCode fallback (e.g. "NXAE104 AES/EBU Network Card")
     * @return Product|null
     */
    public static function findExistingProduct(?int $brandId, ?string $normalizedModel, ?string $rawModelOrFullName = null): ?Product
    {
        if (!$normalizedModel && !$rawModelOrFullName) {
            return null;
        }

        $baseQuery = function ($q) use ($normalizedModel, $rawModelOrFullName) {
            if ($normalizedModel && ProductNormalizer::isValidNormalizedCode($normalizedModel)) {
                $q->where('normalized_model', $normalizedModel);

                $minLen = self::effectiveSubstringMinLen($normalizedModel);

                // Substring matching only when both sides are long enough to avoid false positives
                // (e.g. "debbiessubaru" must not match inventory row "ss" via LIKE '%ss%').
                if (strlen($normalizedModel) >= $minLen) {
                    $q->orWhere(function ($sub) use ($normalizedModel, $minLen) {
                        $sub->whereRaw('LENGTH(COALESCE(normalized_model, \'\')) >= ?', [$minLen])
                            ->whereRaw('? LIKE CONCAT(\'%\', COALESCE(normalized_model, \'\'), \'%\')', [$normalizedModel]);
                    });
                    $q->orWhere(function ($sub) use ($normalizedModel, $minLen) {
                        $sub->whereRaw('LENGTH(COALESCE(normalized_model, \'\')) >= ?', [$minLen])
                            ->whereRaw('COALESCE(normalized_model, \'\') LIKE CONCAT(\'%\', ?, \'%\')', [$normalizedModel]);
                    });
                }

                self::applyPrefixMatchOnNormalizedModel($q, $normalizedModel);
            }

            if ($rawModelOrFullName) {
                $extractedCode = ProductNormalizer::extractModelCode($rawModelOrFullName);
                if ($extractedCode) {
                    $extractedNormalized = ProductNormalizer::normalizeCode($extractedCode);
                    if ($extractedNormalized && ProductNormalizer::isValidNormalizedCode($extractedNormalized)) {
                        $q->orWhere('normalized_model', $extractedNormalized);

                        $extMinLen = self::effectiveSubstringMinLen($extractedNormalized);
                        if (strlen($extractedNormalized) >= $extMinLen) {
                            $q->orWhere(function ($sub) use ($extractedNormalized, $extMinLen) {
                                $sub->whereRaw('LENGTH(COALESCE(normalized_model, \'\')) >= ?', [$extMinLen])
                                    ->whereRaw('? LIKE CONCAT(\'%\', COALESCE(normalized_model, \'\'), \'%\')', [$extractedNormalized]);
                            });
                            $q->orWhere(function ($sub) use ($extractedNormalized, $extMinLen) {
                                $sub->whereRaw('LENGTH(COALESCE(normalized_model, \'\')) >= ?', [$extMinLen])
                                    ->whereRaw('COALESCE(normalized_model, \'\') LIKE CONCAT(\'%\', ?, \'%\')', [$extractedNormalized]);
                            });
                        }

                        self::applyPrefixMatchOnNormalizedModel($q, $extractedNormalized);
                    }
                }
            }
        };

        $query = Product::where(function ($q) use ($baseQuery) {
            $baseQuery($q);
        });

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        }
        // When brand_id is null (no brand matched from Flex name), search across all brands
        // to find products like "NXAE104" that may exist with or without a brand

        if ($normalizedModel !== null && $normalizedModel !== '') {
            $query->orderByRaw('CASE WHEN normalized_model = ? THEN 0 ELSE 1 END', [$normalizedModel]);
        }

        return $query->first();
    }

    /** Flex custom field group name that holds PSM marketplace fields */
    protected const PRO_SUBRENTAL_MARKETPLACE_GROUP_NAME = 'Pro Subrental Marketplace';

    protected const PSM_CODE_FIELD_CAPTION = 'PSM Code';

    protected const PUBLISH_TO_PSM_FIELD_CAPTION = 'Publish to PSM';

    /**
     * GET /f5/api/custom-field-group/inventory-model/groups?resourceId=…
     * Returns the "Pro Subrental Marketplace" group id, or null if missing / API failure.
     */
    protected static function getProSubrentalMarketplaceGroupId(int $companyId, string $resourceId): ?string
    {
        try {
            $service = new self($companyId);
            $path = config(
                'flex.custom_field_inventory_model_groups_path',
                '/f5/api/custom-field-group/inventory-model/groups'
            );
            $url = $service->baseUrl . $path;
            $response = Http::timeout($service->timeout)
                ->withHeaders(array_merge(
                    $service->getAuthHeaders(),
                    ['Content-Type' => 'application/json']
                ))
                ->get($url, ['resourceId' => $resourceId]);

            if (!$response->successful()) {
                Log::debug('Flex custom-field-group groups non-success', [
                    'url' => $url,
                    'resource_id' => $resourceId,
                    'status' => $response->status(),
                    'body_preview' => substr($response->body(), 0, 400),
                ]);

                return null;
            }

            $data = $response->json();
            $groups = self::normalizeFlexListPayload($data);
            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $name = isset($group['name']) ? trim((string) $group['name']) : '';
                if ($name === self::PRO_SUBRENTAL_MARKETPLACE_GROUP_NAME) {
                    $id = $group['id'] ?? null;
                    if ($id !== null && $id !== '') {
                        return (string) $id;
                    }
                }
            }

            Log::debug('Flex custom-field-group: Pro Subrental Marketplace group not found', [
                'resource_id' => $resourceId,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::debug('Flex custom-field-group groups request failed', [
                'resource_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize Flex responses that may be a bare array or wrapped in content/data.
     *
     * @param  mixed  $data
     * @return array<int, mixed>
     */
    protected static function normalizeFlexListPayload($data): array
    {
        if (is_array($data) && array_key_exists(0, $data) && is_array($data[0] ?? null)) {
            return $data;
        }
        if (is_array($data)) {
            $nested = $data['content'] ?? $data['data'] ?? $data['items'] ?? $data['fields'] ?? null;
            if (is_array($nested)) {
                return $nested;
            }
        }

        return [];
    }

    /**
     * Parse boolean-ish values from Flex custom field storedValue / value.
     */
    protected static function parseFlexBooleanish($value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }
        $s = strtolower(trim((string) $value));
        if ($s === '' || $s === 'null') {
            return null;
        }
        if (in_array($s, ['true', 'yes', '1', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['false', 'no', '0', 'off'], true)) {
            return false;
        }

        return null;
    }

    /**
     * GET …/custom-field-value/{groupId}/resource-values?resourceId=…
     *
     * @return array{psm_code: string|null, publish_to_psm: bool|null}|null
     */
    protected static function parseProSubrentalFieldsFromResourceValuesResponse($json): ?array
    {
        $rows = self::normalizeFlexListPayload($json);
        if ($rows === []) {
            $rows = is_array($json) ? [$json] : [];
        }

        $psmCode = null;
        $publishToPsm = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $caption = isset($row['caption']) ? trim((string) $row['caption']) : '';
            if ($caption === self::PSM_CODE_FIELD_CAPTION) {
                $raw = $row['storedValue'] ?? $row['value'] ?? null;
                if ($raw !== null && $raw !== '') {
                    $psmCode = trim((string) $raw);
                }
            }
            if ($caption === self::PUBLISH_TO_PSM_FIELD_CAPTION) {
                $raw = $row['storedValue'] ?? $row['value'] ?? null;
                $publishToPsm = self::parseFlexBooleanish($raw);
            }
        }

        return [
            'psm_code' => $psmCode !== null && $psmCode !== '' ? $psmCode : null,
            'publish_to_psm' => $publishToPsm,
        ];
    }

    /**
     * Fetch PSM Code and Publish to PSM from Flex "Pro Subrental Marketplace" custom fields.
     * Returns null if the group is absent, HTTP fails, or response cannot be parsed (caller should fallback).
     *
     * @return array{psm_code: string|null, publish_to_psm: bool|null}|null
     */
    public static function getProSubrentalMarketplaceCustomFields(int $companyId, string $resourceId): ?array
    {
        $groupId = self::getProSubrentalMarketplaceGroupId($companyId, $resourceId);
        if ($groupId === null) {
            return null;
        }

        try {
            $service = new self($companyId);
            $pattern = config(
                'flex.custom_field_resource_values_path_pattern',
                '/f5/api/custom-field-value/%s/resource-values'
            );
            $path = sprintf($pattern, $groupId);
            $url = $service->baseUrl . $path;
            $response = Http::timeout($service->timeout)
                ->withHeaders(array_merge(
                    $service->getAuthHeaders(),
                    ['Content-Type' => 'application/json']
                ))
                ->get($url, ['resourceId' => $resourceId]);

            if (!$response->successful()) {
                Log::debug('Flex custom-field resource-values non-success', [
                    'url' => $url,
                    'group_id' => $groupId,
                    'resource_id' => $resourceId,
                    'status' => $response->status(),
                    'body_preview' => substr($response->body(), 0, 400),
                ]);

                return null;
            }

            $json = $response->json();
            $parsed = self::parseProSubrentalFieldsFromResourceValuesResponse($json);

            Log::debug('Flex Pro Subrental custom fields parsed', [
                'resource_id' => $resourceId,
                'group_id' => $groupId,
                'has_psm_code' => !empty($parsed['psm_code']),
                'publish_to_psm' => $parsed['publish_to_psm'],
            ]);

            return $parsed;
        } catch (\Throwable $e) {
            Log::debug('Flex custom-field resource-values request failed', [
                'resource_id' => $resourceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Match inventory_master row by Flex "PSM Code" custom field (exact psm_code).
     * With two arguments, custom fields are fetched once. With three arguments, the third value must be
     * the result of getProSubrentalMarketplaceCustomFields() (even if null) to avoid duplicate HTTP.
     *
     * @param  array{psm_code: string|null, publish_to_psm: bool|null}|null  $resolvedCustomFields
     */
    public static function matchUsingPSMCode(int $companyId, string $resourceId, ?array $resolvedCustomFields = null): ?Product
    {
        if (func_num_args() < 3) {
            $resolvedCustomFields = self::getProSubrentalMarketplaceCustomFields($companyId, $resourceId);
        }

        if ($resolvedCustomFields === null) {
            return null;
        }

        $publishToPsm = $resolvedCustomFields['publish_to_psm'] ?? null;
        if ($publishToPsm === false) {
            Log::info('Flex matchUsingPSMCode: Publish to PSM is false (matching still allowed)', [
                'resource_id' => $resourceId,
                'company_id' => $companyId,
            ]);
        }

        $code = isset($resolvedCustomFields['psm_code']) ? trim((string) $resolvedCustomFields['psm_code']) : '';
        if ($code === '') {
            return null;
        }

        $product = Product::where('psm_code', $code)->first();
        if ($product) {
            Log::info('Flex matchUsingPSMCode: matched inventory_master by psm_code', [
                'resource_id' => $resourceId,
                'company_id' => $companyId,
                'psm_code' => $code,
                'product_id' => $product->id,
            ]);
        } else {
            Log::debug('Flex matchUsingPSMCode: no inventory_master row for psm_code (fallback to brand/model)', [
                'resource_id' => $resourceId,
                'company_id' => $companyId,
                'psm_code' => $code,
            ]);
        }

        return $product;
    }
}
