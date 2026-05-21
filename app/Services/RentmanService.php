<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\RentmanEquipment;
use App\Support\CompanyInventorySpecs;
use App\Support\InventoryMeasurementUnits;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RentmanService
{
    protected string $baseUrl;

    protected string $authToken;

    protected int $timeout = 120;

    protected int $companyId;

    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;

        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('integration_type', 'rentman')
            ->first();

        if (!$integration || empty($integration->api_key)) {
            throw new \RuntimeException('Rentman integration not configured for this company.');
        }

        $this->baseUrl = rtrim((string) ($integration->api_base_url ?: config('services.rentman.base_url', '')), '/');
        $this->authToken = $integration->api_key;

        if ($this->baseUrl === '') {
            throw new \RuntimeException('Rentman base URL is not configured.');
        }
    }

    /**
     * Human-readable title for matching / catalog (prefers display name).
     */
    public static function primaryLabel(?RentmanEquipment $row): string
    {
        if (!$row) {
            return '';
        }

        $display = trim((string) ($row->displayname ?? ''));
        if ($display !== '') {
            return $display;
        }

        return trim((string) ($row->name ?? ''));
    }

    /**
     * Payload aligned with Flex import-check shape (extended Rentman fields).
     *
     * @return array<string, mixed>
     */
    public static function importCheckRentmanPayload(RentmanEquipment $row): array
    {
        return [
            'rentman_id' => (string) $row->rentman_id,
            'name' => $row->name,
            'displayname' => $row->displayname,
            'code' => $row->code,
            'subrental_costs' => $row->subrental_costs,
            'rental_sales' => $row->rental_sales,
            'shop_description_long' => $row->shop_description_long,
            'height' => $row->height,
            'width' => $row->width,
            'length' => $row->length,
            'weight' => $row->weight,
            'country_of_origin' => $row->country_of_origin,
            'current_quantity' => $row->current_quantity,
        ];
    }

    /**
     * Fetch live equipment details from Rentman and persist on local cache row.
     *
     * @throws \RuntimeException
     */
    public static function fetchAndStoreEquipmentDetails(int $companyId, string $rentmanId): RentmanEquipment
    {
        $service = new self($companyId);
        $payload = $service->fetchEquipmentDetailsFromApi($rentmanId);
        $attributes = self::mapEquipmentDetailsPayload($payload);
        $rentalSales = strtolower(trim((string) ($attributes['rental_sales'] ?? '')));

        if ($rentalSales !== '' && $rentalSales !== 'rental') {
            throw new \RuntimeException('This equipment is not for rental.');
        }

        $row = RentmanEquipment::query()
            ->where('company_id', $companyId)
            ->where('rentman_id', $rentmanId)
            ->first();

        if (!$row) {
            throw new \RuntimeException('Rentman equipment not found locally. Run a sync first, then try again.');
        }

        $row->forceFill(array_merge($attributes, ['synced_at' => now()]))->save();

        return $row->fresh();
    }

    /**
     * Get equipment image URLs from Rentman files endpoint.
     *
     * @return array<int, string>
     */
    public static function getEquipmentImageUrls(int $companyId, string $rentmanId): array
    {
        $service = new self($companyId);
        $payload = $service->fetchEquipmentFilesFromApi($rentmanId);
        $items = self::extractEquipmentList($payload);
        $urls = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $isImage = $item['image'] ?? null;
            if ($isImage !== true && $isImage !== 1 && $isImage !== '1') {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Full paginated sync from Rentman API into rentman_equipments.
     */
    public function syncAllEquipmentFromApi(): void
    {
        $queryParams = [
            'fields' => 'id,name,displayname,code,updateHash',
            'limit' => 300,
        ];

        $nextUrl = $this->buildEquipmentUrl($queryParams);

        $pageGuard = 0;
        while ($nextUrl !== null && $nextUrl !== '' && $pageGuard < 5000) {
            $pageGuard++;
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->authHeaders())
                ->acceptJson()
                ->get($nextUrl);

            if (!$response->successful()) {
                Log::warning('Rentman equipment sync failed', [
                    'company_id' => $this->companyId,
                    'url' => $nextUrl,
                    'status' => $response->status(),
                    'body_preview' => substr($response->body(), 0, 800),
                ]);
                throw new \RuntimeException(
                    'Rentman equipment sync failed (HTTP ' . $response->status() . ').'
                );
            }

            $payload = $response->json();
            $items = self::extractEquipmentList($payload);

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $normalized = self::normalizeApiEquipmentRow($item);
                if ($normalized['rentman_id'] === '') {
                    continue;
                }
                $this->upsertSyncedRow($normalized);
            }

            $nextUrl = self::extractNextPageUrl($payload);
            if ($nextUrl !== null && !str_starts_with($nextUrl, 'http')) {
                $nextUrl = $this->baseUrl . '/' . ltrim($nextUrl, '/');
            }
        }
    }

    /**
     * Create company_inventory linked to Rentman when attaching an existing inventory_master row.
     *
     * @throws \RuntimeException
     */
    public static function syncExistingProductWithRentmanData(
        int $companyId,
        int $productId,
        string $rentmanEquipmentId,
        ?string $softwareCode,
        int $quantity,
        int $userId,
        ?float $overrideRentalRate = null,
        ?string $description = null
    ): Equipment {
        $product = Product::findOrFail($productId);
        $row = RentmanEquipment::where('company_id', $companyId)
            ->where('rentman_id', $rentmanEquipmentId)
            ->first();

        if ($row) {
            RentmanInventoryImportService::updateProductSpecsIfEmpty($product, $row);
        }

        $linearUnitId = InventoryMeasurementUnits::resolveRentmanLinearUnitId();
        $weightUnitId = InventoryMeasurementUnits::resolveRentmanWeightUnitId();
        $specAttributes = $row
            ? CompanyInventorySpecs::mergeWithProduct(
                $product,
                CompanyInventorySpecs::attributesFromRentmanRow($row, $linearUnitId, $weightUnitId)
            )
            : CompanyInventorySpecs::attributesFromProduct($product);

        $equipment = Equipment::create(array_merge([
            'user_id' => $userId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'rentman_equipment_id' => $rentmanEquipmentId,
            'software_code' => $softwareCode ?? $rentmanEquipmentId,
            'quantity' => $quantity,
            'rental_price' => $overrideRentalRate,
            'description' => $description,
        ], $specAttributes));

        return $equipment;
    }

    protected function buildEquipmentUrl(array $queryParams): string
    {
        return $this->baseUrl . '/equipment?' . http_build_query($queryParams);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchEquipmentDetailsFromApi(string $rentmanId): array
    {
        $url = $this->baseUrl . '/equipment/' . urlencode($rentmanId);
        $response = Http::timeout($this->timeout)
            ->withHeaders($this->authHeaders())
            ->acceptJson()
            ->get($url);

        if (!$response->successful()) {
            Log::warning('Rentman equipment details fetch failed', [
                'company_id' => $this->companyId,
                'rentman_id' => $rentmanId,
                'url' => $url,
                'status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 800),
            ]);
            throw new \RuntimeException('Unable to fetch Rentman equipment details (HTTP ' . $response->status() . ').');
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid Rentman equipment details response.');
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }
        if (isset($payload['equipment']) && is_array($payload['equipment'])) {
            return $payload['equipment'];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchEquipmentFilesFromApi(string $rentmanId): array
    {
        $url = $this->baseUrl . '/equipment/' . urlencode($rentmanId) . '/files';
        $response = Http::timeout($this->timeout)
            ->withHeaders($this->authHeaders())
            ->acceptJson()
            ->get($url, ['limit' => 300]);

        if (!$response->successful()) {
            Log::warning('Rentman equipment files fetch failed', [
                'company_id' => $this->companyId,
                'rentman_id' => $rentmanId,
                'url' => $url,
                'status' => $response->status(),
                'body_preview' => substr($response->body(), 0, 800),
            ]);
            throw new \RuntimeException('Unable to fetch Rentman equipment files (HTTP ' . $response->status() . ').');
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid Rentman equipment files response.');
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->authToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param  mixed  $payload
     * @return array<int, mixed>
     */
    protected static function extractEquipmentList($payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $items = $payload['data']
            ?? $payload['items']
            ?? $payload['results']
            ?? $payload['equipment']
            ?? null;
        if ($items === null && array_is_list($payload)) {
            return $payload;
        }

        return is_array($items) ? $items : [];
    }

    /**
     * @param  mixed  $payload
     */
    protected static function extractNextPageUrl($payload): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        $next = $payload['next_page_url'] ?? $payload['nextPageUrl'] ?? $payload['next'] ?? null;

        return is_string($next) && $next !== '' ? $next : null;
    }

    /**
     * @return array{rentman_id: string, name: ?string, displayname: ?string, code: ?string, update_hash: string}
     */
    protected static function normalizeApiEquipmentRow(array $item): array
    {
        $id = $item['id'] ?? $item['Id'] ?? null;
        $hash = $item['updateHash'] ?? $item['update_hash'] ?? $item['updatehash'] ?? '';

        return [
            'rentman_id' => $id !== null && $id !== '' ? (string) $id : '',
            'name' => isset($item['name']) ? (string) $item['name'] : null,
            'displayname' => isset($item['displayname'])
                ? (string) $item['displayname']
                : (isset($item['displayName']) ? (string) $item['displayName'] : null),
            'code' => isset($item['code']) ? (string) $item['code'] : null,
            'update_hash' => (string) $hash,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected static function mapEquipmentDetailsPayload(array $payload): array
    {
        return [
            'name' => self::stringOrNull($payload['name'] ?? null),
            'displayname' => self::stringOrNull($payload['displayname'] ?? ($payload['displayName'] ?? null)),
            'code' => self::stringOrNull($payload['code'] ?? null),
            'subrental_costs' => self::floatOrNull($payload['subrental_costs'] ?? ($payload['subrentalCosts'] ?? null)),
            'rental_sales' => self::stringOrNull($payload['rental_sales'] ?? ($payload['rentalSales'] ?? null)),
            'shop_description_long' => self::stringOrNull($payload['shop_description_long'] ?? ($payload['shopDescriptionLong'] ?? null)),
            'height' => self::floatOrNull($payload['height'] ?? null),
            'width' => self::floatOrNull($payload['width'] ?? null),
            'length' => self::floatOrNull($payload['length'] ?? null),
            'weight' => self::floatOrNull($payload['weight'] ?? null),
            'country_of_origin' => self::stringOrNull($payload['country_of_origin'] ?? ($payload['countryOfOrigin'] ?? null)),
            'current_quantity' => self::intOrNull($payload['current_quantity'] ?? ($payload['currentQuantity'] ?? null)),
        ];
    }

    protected static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    protected static function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    protected static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param  array{rentman_id: string, name: ?string, displayname: ?string, code: ?string, update_hash: string}  $normalized
     */
    protected function upsertSyncedRow(array $normalized): void
    {
        $companyId = $this->companyId;
        $rid = $normalized['rentman_id'];
        $newHash = $normalized['update_hash'];

        $existing = RentmanEquipment::query()
            ->where('company_id', $companyId)
            ->where('rentman_id', $rid)
            ->first();

        $syncTime = now();

        if ($existing !== null && (string) ($existing->update_hash ?? '') === $newHash) {
            $existing->forceFill(['synced_at' => $syncTime])->save();

            return;
        }

        RentmanEquipment::updateOrCreate(
            [
                'company_id' => $companyId,
                'rentman_id' => $rid,
            ],
            [
                'name' => $normalized['name'],
                'displayname' => $normalized['displayname'],
                'code' => $normalized['code'],
                'update_hash' => $newHash !== '' ? $newHash : null,
                'synced_at' => $syncTime,
            ]
        );
    }
}
