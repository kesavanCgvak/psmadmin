<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlexService
{
    protected ?string $baseUrl = null;

    protected ?string $apiKey = null;

    protected int $timeout = 15;

    /**
     * Create a new FlexService instance for the given company.
     * Loads credentials from company_integrations.
     *
     * @param int $companyId
     * @throws \RuntimeException When integration is missing or invalid
     */
    public function __construct(int $companyId)
    {
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
}
