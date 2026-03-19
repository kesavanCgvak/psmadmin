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

            if (!$response->successful()) {
                Log::warning('Flex pricing API error', [
                    'url' => $url,
                    'resource_id' => $flexId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $rows = $data['content'] ?? $data['rows'] ?? $data['data'] ?? (is_array($data) ? $data : []);

            if (!is_array($rows)) {
                return null;
            }

            foreach ($rows as $row) {
                $modelName = $row['pricingModelName'] ?? $row['pricing_model_name'] ?? $row['name'] ?? '';
                if (stripos((string) $modelName, 'Day Rate') !== false) {
                    $price = $row['retailPricePerUnit'] ?? $row['retail_price_per_unit'] ?? $row['price'] ?? null;
                    if ($price !== null && $price !== '') {
                        return (float) $price;
                    }
                }
            }

            return null;
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
                $items = $data['content'] ?? $data['currencies'] ?? $data['data'] ?? null;

                if (is_array($items)) {
                    foreach ($items as $item) {
                        $isoCode = $item['isoCode'] ?? $item['iso_code'] ?? $item['code'] ?? null;
                        if (strtoupper((string) $isoCode) === 'USD') {
                            return (string) ($item['id'] ?? $item['currencyId'] ?? null);
                        }
                    }
                }

                if (is_array($data)) {
                    $isoCode = $data['isoCode'] ?? $data['iso_code'] ?? $data['code'] ?? null;
                    if (strtoupper((string) $isoCode) === 'USD') {
                        return (string) ($data['id'] ?? $data['currencyId'] ?? null);
                    }
                }

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
            $service = new self($companyId);
            $currencyId = self::getUsdCurrencyId($companyId);

            if (!$currencyId) {
                Log::debug('Flex Day Rate: USD currency not found, skipping pricing fetch');
                return null;
            }

            $path = config('flex.pricing_path', '/f5/api/resource-pricing/grid-node');
            $url = $service->baseUrl . $path;
            $params = [
                'resourceId' => $resourceId,
                'currencyId' => $currencyId,
            ];

            $response = Http::timeout($service->timeout)
                ->withHeaders(array_merge(
                    $service->getAuthHeaders(),
                    ['Content-Type' => 'application/json']
                ))
                ->get($url, $params);

            if (!$response->successful()) {
                Log::warning('Flex pricing API error', [
                    'url' => $url,
                    'resource_id' => $resourceId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $rows = $data['content'] ?? $data['rows'] ?? $data['data'] ?? (is_array($data) ? $data : []);

            if (!is_array($rows)) {
                return null;
            }

            foreach ($rows as $row) {
                $modelName = $row['pricingModelName'] ?? $row['pricing_model_name'] ?? $row['name'] ?? '';
                if (stripos((string) $modelName, 'Day Rate') !== false) {
                    $price = $row['retailPricePerUnit'] ?? $row['retail_price_per_unit'] ?? $row['price'] ?? null;
                    if ($price !== null && $price !== '') {
                        return (float) $price;
                    }
                }
            }

            return null;
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

        // 2. Fetch pricing
        $dayRate = $overrideRentalRate;
        if ($dayRate === null) {
            $currencyId = $service->getCurrencyId();
            $dayRate = $currencyId ? $service->getDayRate($flexId, $currencyId) : null;
        }

        // 3. Update inventory_master ONLY if values exist (do not overwrite with null)
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
            $flexKey = $flexMapping[$dbField];
            $value = $details[$flexKey] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            // If replacementCost is 0, do not overwrite existing product replacement_price
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
     * Find existing product in inventory_master by brand_id + normalized model.
     *
     * @param int|null $brandId Brand ID
     * @param string|null $normalizedModel Normalized model string
     * @return Product|null
     */
    public static function findExistingProduct(?int $brandId, ?string $normalizedModel): ?Product
    {
        if (!$normalizedModel) {
            return null;
        }

        $query = Product::where('normalized_model', $normalizedModel);

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        } else {
            $query->whereNull('brand_id');
        }

        return $query->first();
    }
}
