<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentImage;
use App\Models\LinearUnit;
use App\Models\Product;
use App\Models\WeightUnit;
use App\Services\FlexService;
use App\Support\PsmCodeGenerator;
use App\Support\ProductNormalizer;
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
     * Import equipment from Flex into PSM.
     * POST /api/flex/import
     * Payload: { flex_id, name, quantity, rental_rate }
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
        $rentalRate = $request->has('rental_rate') ? (float) $request->input('rental_rate') : null;

        try {
            // Step 1: Fetch details from Flex
            $details = FlexService::getInventoryDetails($companyId, $flexId);
            $name = $details['name'] ?? $request->input('name', '');
            $sku = $details['sku'] ?? null;
            $softwareCode = $sku ?? (string) $flexId;

            // Step 2: Check if product exists in inventory_master (by normalized model)
            $normalized = ProductNormalizer::normalizeCode($name);
            $existingProduct = Product::where('normalized_model', $normalized)->first();

            // Fallback: Flex may add prefix (e.g. "APOGEE - CLAY PAKY - Sharpy Wash 330")
            // Match when existing product's normalized_model is contained in Flex normalized string
            if (!$existingProduct && $normalized && strlen($normalized) >= 6) {
                $existingProduct = Product::whereNotNull('normalized_model')
                    ->whereRaw('LENGTH(normalized_model) >= 6')
                    ->whereRaw('LOCATE(normalized_model, ?) > 0', [$normalized])
                    ->orderByRaw('LENGTH(normalized_model) DESC')
                    ->first();
            }

            if ($existingProduct) {
                // Product exists in PSM - check company_inventory for this company + product
                $existingInventory = Equipment::where('company_id', $companyId)
                    ->where('product_id', $existingProduct->id)
                    ->first();

                if ($existingInventory) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Product already exists in your marketplace inventory.',
                    ], 409);
                }

                // Link to existing product - create company_inventory
                return $this->createCompanyInventory(
                    $user,
                    $companyId,
                    $existingProduct->id,
                    $flexId,
                    $softwareCode,
                    $quantity,
                    $rentalRate,
                    $details['imageUrls'] ?? []
                );
            }

            // Step 3: Product does not exist - create unverified inventory_master + company_inventory
            return $this->createProductAndInventory(
                $user,
                $companyId,
                $flexId,
                $details,
                $softwareCode,
                $quantity,
                $rentalRate
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
     * Create company_inventory record when product already exists.
     */
    protected function createCompanyInventory(
        $user,
        int $companyId,
        int $productId,
        string $flexResourceId,
        string $softwareCode,
        int $quantity,
        ?float $rentalRate,
        array $imageUrls = []
    ): JsonResponse {
        DB::beginTransaction();
        try {
            $equipment = Equipment::create([
                'user_id' => $user->id,
                'company_id' => $companyId,
                'product_id' => $productId,
                'flex_resource_id' => $flexResourceId,
                'software_code' => $softwareCode,
                'quantity' => $quantity,
                'rental_price' => $rentalRate ?? 0,
            ]);

            $this->createEquipmentImages($equipment->id, $imageUrls);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Equipment imported successfully.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product already exists in your marketplace inventory.',
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
        string $softwareCode,
        int $quantity,
        ?float $rentalRate
    ): JsonResponse {
        $linearUnitId = $this->resolveLinearUnitId($details['linearUnit'] ?? null);
        $weightUnitId = $this->resolveWeightUnitId($details['weightUnit'] ?? null);
        // Flex does not provide category or brand - leave NULL instead of assigning defaults
        $categoryId = null;
        $brandId = null;

        DB::beginTransaction();
        try {
            $product = Product::create([
                'category_id' => $categoryId,
                'sub_category_id' => null,
                'brand_id' => $brandId,
                'model' => $details['name'] ?? 'Unknown',
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
                'message' => 'Equipment imported successfully.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product already exists in your marketplace inventory.',
                ], 409);
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
