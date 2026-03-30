<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentImage;
use App\Models\LinearUnit;
use App\Models\Product;
use App\Models\WeightUnit;
use App\Services\FlexService;
use App\Services\InventoryImportService;
use App\Support\PsmCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class FlexInventoryController extends Controller
{
    /**
     * Search Flex inventory.
     * GET /api/flex/search?keyword=...
     */
    public function search(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = $user->company_id;
        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        $keyword = $request->query('keyword', '');
        if (strlen(trim($keyword)) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Keyword must be at least 2 characters.',
            ], 422);
        }

        try {
            $results = FlexService::searchInventory($companyId, trim($keyword));
            return response()->json($results, 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Flex search error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while searching Flex inventory.',
            ], 500);
        }
    }

    /**
     * Check import status for frontend confirmation flow.
     * GET /api/flex/import/check?flex_id=...
     * Returns: product_exists, already_in_inventory, or new_product.
     */
    public function checkImport(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = $user->company_id;
        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        $flexId = (string) ($request->query('flex_id') ?? $request->input('flex_id', ''));
        if ($flexId === '') {
            return response()->json([
                'success' => false,
                'message' => 'flex_id is required.',
            ], 422);
        }

        try {
            $result = InventoryImportService::checkImportStatus($companyId, $flexId);

            if ($result['status'] === 'already_in_inventory') {
                return response()->json([
                    'status' => 'already_in_inventory',
                    'message' => $result['message'],
                    'brand_name' => $result['brand_name'] ?? null,
                    'model' => $result['model'] ?? null,
                    'flex' => $result['flex'] ?? null,
                ], 200);
            }

            if ($result['status'] === 'inventory_exists') {
                return response()->json([
                    'status' => 'inventory_exists',
                    'inventory_id' => $result['inventory_id'],
                    'brand_name' => $result['brand_name'] ?? null,
                    'model' => $result['model'] ?? null,
                    'flex' => $result['flex'] ?? null,
                ], 200);
            }

            if ($result['status'] === 'product_exists') {
                return response()->json([
                    'status' => 'product_exists',
                    'product_id' => $result['product_id'],
                    'day_rate' => $result['day_rate'],
                    'brand_name' => $result['brand_name'] ?? null,
                    'model' => $result['model'] ?? null,
                    'flex' => $result['flex'] ?? null,
                ], 200);
            }

            return response()->json([
                'status' => 'new_product',
                'day_rate' => $result['day_rate'],
                'flex' => $result['flex'] ?? null,
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Flex import check error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while checking import status.',
            ], 500);
        }
    }

    /**
     * Link an existing company_inventory item to a Flex resource.
     * POST /api/flex/link-inventory
     * Payload: { inventory_id, flex_id }
     */
    public function linkInventory(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = $user->company_id;
        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'inventory_id' => 'required|integer|min:1',
            'flex_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $inventoryId = (int) $request->input('inventory_id');
        $flexId = (string) $request->input('flex_id');

        try {
            $result = InventoryImportService::linkFlexToExistingInventory($companyId, $inventoryId, $flexId);

            if (isset($result['status']) && $result['status'] === 'already_linked') {
                return response()->json([
                    'success' => false,
                    'status' => 'already_linked',
                    'message' => $result['message'],
                ], 200);
            }

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Flex link-inventory error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while linking Flex.',
            ], 500);
        }
    }

    /**
     * Import equipment from Flex into PSM.
     * POST /api/flex/import
     * Payload: { flex_id, quantity, rental_rate?, rental_price?, confirm?, product_id? }
     * When product_id is set, that inventory_master row is used (link unlinked row or add company_inventory).
     * rental_price overrides Flex Day Rate when present; otherwise rental_rate is used as override.
     */
    public function import(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = $user->company_id;
        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'flex_id' => 'required',
            'quantity' => 'required|numeric|min:1',
            'rental_rate' => 'nullable|numeric|min:0',
            'rental_price' => 'nullable|numeric|min:0',
            'confirm' => 'nullable|boolean',
            'product_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $flexId = (string) $request->input('flex_id');
        $quantity = (int) $request->input('quantity');
        $confirm = $request->boolean('confirm', true);

        $rentalOverride = null;
        if ($request->exists('rental_price')) {
            $rentalOverride = (float) $request->input('rental_price');
        } elseif ($request->has('rental_rate')) {
            $rentalOverride = (float) $request->input('rental_rate');
        }

        $explicitProductId = $request->filled('product_id') ? (int) $request->input('product_id') : null;

        try {
            $checkResult = InventoryImportService::checkImportStatus($companyId, $flexId);

            if ($checkResult['status'] === 'already_in_inventory') {
                $eq = Equipment::where('company_id', $companyId)
                    ->where('flex_resource_id', $flexId)
                    ->first();
                if ($explicitProductId && $eq && (int) $eq->product_id === $explicitProductId) {
                    // Allow import path to refresh qty / rental / Flex data for this product
                } elseif (!$explicitProductId) {
                    return response()->json([
                        'success' => false,
                        'status' => 'already_in_inventory',
                        'message' => $checkResult['message'],
                    ], 200);
                } else {
                    return response()->json([
                        'success' => false,
                        'status' => 'already_in_inventory',
                        'message' => 'This Flex resource is already linked to a different product.',
                    ], 200);
                }
            }

            if ($checkResult['status'] === 'inventory_exists' && !$explicitProductId) {
                return response()->json([
                    'status' => 'inventory_exists',
                    'inventory_id' => $checkResult['inventory_id'],
                ], 200);
            }

            $details = FlexService::getInventoryDetails($companyId, $flexId);
            $name = $details['name'] ?? $request->input('name', '');
            $softwareCode = $details['sku'] ?? $details['partNumber'] ?? $flexId;
            $parsed = FlexService::parseBrandAndModel($name);
            $existingProduct = ($checkResult['status'] === 'product_exists')
                ? Product::find($checkResult['product_id'])
                : null;

            if ($explicitProductId) {
                if (!Product::whereKey($explicitProductId)->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Product not found.',
                    ], 404);
                }

                if (!$confirm) {
                    $dayRate = $rentalOverride !== null
                        ? $rentalOverride
                        : FlexService::getDayRentalRate($companyId, $flexId);

                    return response()->json([
                        'status' => 'product_exists',
                        'product_id' => $explicitProductId,
                        'day_rate' => $dayRate,
                    ], 200);
                }

                try {
                    InventoryImportService::importFlexWithExplicitProductId(
                        $companyId,
                        $user->id,
                        $explicitProductId,
                        $flexId,
                        $quantity,
                        $rentalOverride,
                        $softwareCode,
                        $details['imageUrls'] ?? []
                    );
                } catch (\Exception $e) {
                    if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                        return response()->json([
                            'success' => false,
                            'status' => 'already_in_inventory',
                            'message' => 'Already imported',
                        ], 409);
                    }
                    throw $e;
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Equipment imported and synced with Flex data',
                ], 201);
            }

            if (!$confirm) {
                $dayRate = $rentalOverride !== null
                    ? $rentalOverride
                    : FlexService::getDayRentalRate($companyId, $flexId);
                if ($existingProduct) {
                    return response()->json([
                        'status' => 'product_exists',
                        'product_id' => $existingProduct->id,
                        'day_rate' => $dayRate,
                    ], 200);
                }

                return response()->json([
                    'status' => 'new_product',
                    'day_rate' => $dayRate,
                ], 200);
            }

            if ($existingProduct) {
                return $this->importExistingProduct(
                    $user,
                    $companyId,
                    $existingProduct->id,
                    $flexId,
                    $softwareCode,
                    $quantity,
                    $rentalOverride,
                    $details['imageUrls'] ?? []
                );
            }

            return $this->createProductAndInventory(
                $user,
                $companyId,
                $flexId,
                $details,
                $parsed,
                $softwareCode,
                $quantity,
                $rentalOverride ?? FlexService::getDayRentalRate($companyId, $flexId)
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Flex import error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during import.',
            ], 500);
        }
    }

    /**
     * Import equipment when product already exists in inventory_master.
     * Syncs Flex data (dimensions, replacement_price, Day Rate) and creates company_inventory.
     */
    protected function importExistingProduct(
        $user,
        int $companyId,
        int $productId,
        string $flexResourceId,
        ?string $softwareCode,
        int $quantity,
        ?float $overrideRentalRate,
        array $imageUrls = []
    ): JsonResponse {
        DB::beginTransaction();
        try {
            FlexService::syncExistingProductWithFlexData(
                $companyId,
                $productId,
                $flexResourceId,
                $softwareCode,
                $quantity,
                $user->id,
                $imageUrls,
                $overrideRentalRate
            );

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Equipment imported and synced with Flex data',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return response()->json([
                    'success' => false,
                    'status' => 'already_in_inventory',
                    'message' => 'Already imported',
                ], 409);
            }
            throw $e;
        }
    }

    /**
     * Create new inventory_master (unverified) and company_inventory.
     */
    protected function createProductAndInventory(
        $user,
        int $companyId,
        string $flexResourceId,
        array $details,
        array $parsed,
        ?string $softwareCode,
        int $quantity,
        ?float $rentalRate
    ): JsonResponse {
        $linearUnitId = $this->resolveLinearUnitId($details['linearUnit'] ?? null);
        $weightUnitId = $this->resolveWeightUnitId($details['weightUnit'] ?? null);
        $categoryId = null;
        $brandId = $parsed['brand_id'] ?? null;
        $model = $parsed['model'] ?? $details['name'] ?? 'Unknown';

        DB::beginTransaction();
        try {
            $product = Product::create([
                'category_id' => $categoryId,
                'sub_category_id' => null,
                'brand_id' => $brandId,
                'model' => $model,
                'psm_code' => PsmCodeGenerator::generateNext(),
                'is_verified' => 0,
                'height' => $details['height'] ?? null,
                'width' => $details['width'] ?? null,
                'length' => $details['modelLength'] ?? null,
                'weight' => $details['weight'] ?? null,
                'linear_unit_id' => $linearUnitId,
                'weight_unit_id' => $weightUnitId,
                'replacement_price' => $details['replacementCost'] ?? null,
                'source' => 'flex',
            ]);

            Log::info('Created new product from Flex import', [
                'product_id' => $product->id,
                'flex_resource_id' => $flexResourceId,
                'company_id' => $companyId,
            ]);

            $equipment = Equipment::create([
                'user_id' => $user->id,
                'company_id' => $companyId,
                'product_id' => $product->id,
                'flex_resource_id' => $flexResourceId,
                'software_code' => $softwareCode,
                'quantity' => $quantity,
                'rental_price' => $rentalRate ?? 0,
                'replacement_price' => $details['replacementCost'] ?? null,
            ]);

            $this->createEquipmentImages($equipment->id, $details['imageUrls'] ?? []);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Equipment imported successfully',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return response()->json(['status' => 'already_in_inventory'], 409);
            }
            throw $e;
        }
    }

    /**
     * Create equipment_images records for Flex image URLs.
     */
    protected function createEquipmentImages(int $equipmentId, array $imageUrls): void
    {
        foreach ($imageUrls as $url) {
            $url = trim($url);
            if (empty($url)) {
                continue;
            }
            EquipmentImage::create([
                'equipment_id' => $equipmentId,
                'image_path' => $url,
            ]);
        }
    }

    /** Flex unit name variations for matching PSM linear_units/weight_units */
    protected const LINEAR_UNIT_ALIASES = [
        'feet' => 'foot',
        'ft' => 'foot',
        'inches' => 'inch',
        'in' => 'inch',
        'meters' => 'meter',
        'm' => 'meter',
        'centimeters' => 'centimeter',
        'cm' => 'centimeter',
    ];

    protected const WEIGHT_UNIT_ALIASES = [
        'pounds' => 'pound',
        'lbs' => 'pound',
        'lb' => 'pound',
        'kilograms' => 'kilogram',
        'kg' => 'kilogram',
        'grams' => 'gram',
        'g' => 'gram',
    ];

    /**
     * Resolve linear unit ID from Flex unit name (e.g. Foot, Feet, Meter).
     */
    protected function resolveLinearUnitId(?string $name): ?int
    {
        if (!$name) {
            return null;
        }
        $normalized = strtolower(trim($name));
        $canonical = self::LINEAR_UNIT_ALIASES[$normalized] ?? $normalized;

        $unit = LinearUnit::whereRaw('LOWER(name) = ?', [$canonical])->first()
            ?: LinearUnit::whereRaw('LOWER(name) = ?', [$normalized])->first();

        return $unit?->id;
    }

    /**
     * Resolve weight unit ID from Flex unit name (e.g. Pound, Pounds, Kilogram).
     */
    protected function resolveWeightUnitId(?string $name): ?int
    {
        if (!$name) {
            return null;
        }
        $normalized = strtolower(trim($name));
        $canonical = self::WEIGHT_UNIT_ALIASES[$normalized] ?? $normalized;

        $unit = WeightUnit::whereRaw('LOWER(name) = ?', [$canonical])->first()
            ?: WeightUnit::whereRaw('LOWER(name) = ?', [$normalized])->first();

        return $unit?->id;
    }

    protected function getAuthenticatedUser()
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return null;
        }
    }
}
