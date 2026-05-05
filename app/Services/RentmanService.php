<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\RentmanEquipment;
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
     * Payload aligned with Flex import-check shape (minimal Rentman fields).
     *
     * @return array{rentman_id: string, name: ?string, displayname: ?string, code: ?string}
     */
    public static function importCheckRentmanPayload(RentmanEquipment $row): array
    {
        return [
            'rentman_id' => (string) $row->rentman_id,
            'name' => $row->name,
            'displayname' => $row->displayname,
            'code' => $row->code,
        ];
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
        ?float $overrideRentalRate = null
    ): Equipment {
        Product::findOrFail($productId);

        $equipment = Equipment::create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'rentman_equipment_id' => $rentmanEquipmentId,
            'software_code' => $softwareCode ?? $rentmanEquipmentId,
            'quantity' => $quantity,
            'rental_price' => $overrideRentalRate,
        ]);

        return $equipment;
    }

    protected function buildEquipmentUrl(array $queryParams): string
    {
        return $this->baseUrl . '/equipment?' . http_build_query($queryParams);
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
