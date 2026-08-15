<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmRentmanSyncRequest;
use App\Http\Requests\SearchRentmanProductRequest;
use App\Jobs\SyncRentmanEquipmentJob;
use App\Models\CompanyIntegration;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\RentmanEquipment;
use App\Services\FlexService;
use App\Services\RentmanIntegrationService;
use App\Services\RentmanInventoryImportService;
use App\Services\RentmanService;
use App\Support\InventoryMeasurementUnits;
use App\Support\PsmCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class RentmanEquipmentController extends Controller
{
    /**
     * POST /api/rentman/equipment/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        if (!CompanyIntegration::where('company_id', $companyId)->where('integration_type', 'rentman')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Rentman integration is not configured for this company.',
            ], 422);
        }

        Log::info('Manual Rentman sync requested; running sync immediately.', [
            'company_id' => $companyId,
            'queue_connection' => config('queue.default'),
        ]);

        SyncRentmanEquipmentJob::dispatchSync($companyId);

        return response()->json([
            'success' => true,
            'message' => 'Rentman equipment sync completed successfully.',
        ], 200);
    }

    /**
     * GET /api/rentman/equipment/search?q=
     */
    public function search(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Query must be at least 2 characters.',
            ], 422);
        }

        $rows = RentmanEquipment::searchLocal($companyId, $q);
        $data = $rows->map(function (RentmanEquipment $row) {
            return [
                'rentman_id' => (string) $row->rentman_id,
                'name' => RentmanService::primaryLabel($row),
                'code' => $row->code,
                'is_imported' => (bool) $row->is_imported,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
        ], 200);
    }

    /**
     * GET /api/rentman/equipment/import/check?rentman_id=
     */
    public function checkImport(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        $rentmanId = (string) ($request->query('rentman_id') ?? '');
        if ($rentmanId === '') {
            return response()->json([
                'success' => false,
                'message' => 'rentman_id is required.',
            ], 422);
        }

        try {
            $result = RentmanInventoryImportService::checkImportStatus($companyId, $rentmanId);

            if ($result['status'] === 'already_in_inventory') {
                return response()->json([
                    'status' => 'already_in_inventory',
                    'message' => $result['message'],
                    'brand_name' => $result['brand_name'] ?? null,
                    'model' => $result['model'] ?? null,
                    'rentman' => $result['rentman'] ?? null,
                    'psm' => $result['psm'] ?? null,
                ], 200);
            }

            if ($result['status'] === 'inventory_exists') {
                return response()->json([
                    'status' => 'inventory_exists',
                    'inventory_id' => $result['inventory_id'],
                    'brand_name' => $result['brand_name'] ?? null,
                    'model' => $result['model'] ?? null,
                    'rentman' => $result['rentman'] ?? null,
                    'psm' => $result['psm'] ?? null,
                ], 200);
            }

            if ($result['status'] === 'product_exists') {
                return response()->json([
                    'status' => 'product_exists',
                    'product_id' => $result['product_id'],
                    'day_rate' => $result['day_rate'],
                    'brand_name' => $result['brand_name'] ?? null,
                    'model' => $result['model'] ?? null,
                    'rentman' => $result['rentman'] ?? null,
                    'psm' => $result['psm'] ?? null,
                ], 200);
            }

            return response()->json([
                'status' => 'new_product',
                'day_rate' => $result['day_rate'],
                'rentman' => $result['rentman'] ?? null,
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Rentman import check error', [
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
     * POST /api/rentman/equipment/link-inventory
     */
    public function linkInventory(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'inventory_id' => 'required|integer|min:1',
            'rentman_id' => 'required|string',
            'quantity' => 'nullable|integer|min:1',
            'rental_rate' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $inventoryId = (int) $request->input('inventory_id');
        $rentmanId = (string) $request->input('rentman_id');
        $quantity = $request->filled('quantity') ? (int) $request->input('quantity') : null;
        $rentalOverride = $request->filled('rental_rate') ? (float) $request->input('rental_rate') : null;
        $row = RentmanService::fetchAndStoreEquipmentDetails($companyId, $rentmanId);
        $this->assertRentmanMandatoryFields($row);
        if ($rentalOverride === null && $row->subrental_costs !== null) {
            $rentalOverride = (float) $row->subrental_costs;
        }
        $description = trim((string) ($row->shop_description_long ?? ''));
        $description = $description === '' ? null : $description;

        try {
            $result = RentmanInventoryImportService::linkRentmanToExistingInventory(
                $companyId,
                $inventoryId,
                $rentmanId,
                $quantity,
                $rentalOverride,
                $description
            );

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
            Log::error('Rentman link-inventory error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while linking Rentman.',
            ], 500);
        }
    }

    /**
     * POST /api/rentman/equipment/import
     */
    public function import(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $companyId = (int) ($user->company_id ?? 0);
        if ($companyId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found for this user.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'rentman_id' => 'required|string',
            'quantity' => 'required|numeric|min:1',
            'rental_rate' => 'nullable|numeric|min:0',
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

        $rentmanId = (string) $request->input('rentman_id');
        $quantity = (int) $request->input('quantity');
        $confirm = $request->boolean('confirm', true);

        $rentalOverride = $request->filled('rental_rate') ? (float) $request->input('rental_rate') : null;

        $explicitProductId = $request->filled('product_id') ? (int) $request->input('product_id') : null;

        $row = RentmanEquipment::where('company_id', $companyId)->where('rentman_id', $rentmanId)->first();
        if (!$row) {
            return response()->json([
                'success' => false,
                'message' => 'Rentman equipment not found locally. Run a sync first.',
            ], 404);
        }

        $row = RentmanService::fetchAndStoreEquipmentDetails($companyId, $rentmanId);
        $this->assertRentmanMandatoryFields($row);
        $label = RentmanService::primaryLabel($row);
        $parsed = FlexService::parseBrandAndModel($label);
        $softwareCode = trim((string) ($row->code ?? '')) !== '' ? trim((string) $row->code) : $rentmanId;
        if ($rentalOverride === null && $row->subrental_costs !== null) {
            $rentalOverride = (float) $row->subrental_costs;
        }
        $description = trim((string) ($row->shop_description_long ?? ''));
        $description = $description === '' ? null : $description;

        try {
            $checkResult = RentmanInventoryImportService::checkImportStatus($companyId, $rentmanId);

            if ($checkResult['status'] === 'already_in_inventory') {
                $eq = Equipment::where('company_id', $companyId)
                    ->where('rentman_equipment_id', $rentmanId)
                    ->first();
                if ($explicitProductId && $eq && (int) $eq->product_id === $explicitProductId) {
                    // allow refresh path below
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
                        'message' => 'This Rentman equipment is already linked to a different product.',
                    ], 200);
                }
            }

            if ($explicitProductId) {
                if (!Product::whereKey($explicitProductId)->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Product not found.',
                    ], 404);
                }

                $existingInventoryForProduct = Equipment::where('company_id', $companyId)
                    ->where('product_id', $explicitProductId)
                    ->first();
                if ($existingInventoryForProduct) {
                    return response()->json([
                        'status' => 'inventory_exists',
                        'inventory_id' => $existingInventoryForProduct->id,
                        'brand_name' => $checkResult['brand_name'] ?? null,
                        'model' => $checkResult['model'] ?? null,
                        'rentman' => $checkResult['rentman'] ?? null,
                    ], 200);
                }

                if (!$confirm) {
                    return response()->json([
                        'status' => 'product_exists',
                        'product_id' => $explicitProductId,
                        'day_rate' => null,
                    ], 200);
                }

                try {
                    RentmanInventoryImportService::importRentmanWithExplicitProductId(
                        $companyId,
                        (int) $user->id,
                        $explicitProductId,
                        $rentmanId,
                        $quantity,
                        $rentalOverride,
                        $softwareCode,
                        $description
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
                    'message' => 'Equipment imported and linked to Rentman',
                ], 201);
            }

            if (!$confirm) {
                return response()->json([
                    'status' => 'new_product',
                    'day_rate' => null,
                ], 200);
            }

            return $this->createProductAndInventory(
                $user,
                $companyId,
                $rentmanId,
                $row,
                $parsed,
                $softwareCode,
                $quantity,
                $rentalOverride,
                $description
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Rentman import error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during import.',
            ], 500);
        }
    }

    protected function createProductAndInventory(
        $user,
        int $companyId,
        string $rentmanEquipmentId,
        RentmanEquipment $row,
        array $parsed,
        string $softwareCode,
        int $quantity,
        ?float $rentalRate,
        ?string $description
    ): JsonResponse {
        $brandId = $parsed['brand_id'] ?? null;
        $model = $parsed['model'] ?? RentmanService::primaryLabel($row) ?: 'Unknown';
        $linearUnitId = InventoryMeasurementUnits::resolveRentmanLinearUnitId();
        $weightUnitId = InventoryMeasurementUnits::resolveRentmanWeightUnitId();

        DB::beginTransaction();
        try {
            $product = Product::create([
                'category_id' => null,
                'sub_category_id' => null,
                'brand_id' => $brandId,
                'model' => $model,
                'psm_code' => PsmCodeGenerator::generateNext(),
                'is_verified' => 0,
                'height' => $row->height,
                'width' => $row->width,
                'length' => $row->length,
                'weight' => $row->weight,
                'linear_unit_id' => $linearUnitId,
                'weight_unit_id' => $weightUnitId,
                'replacement_price' => null,
                'country_of_origin' => $row->country_of_origin !== null && trim((string) $row->country_of_origin) !== ''
                    ? strtoupper(trim((string) $row->country_of_origin))
                    : null,
                'source' => 'rentman',
            ]);

            $specAttributes = \App\Support\CompanyInventorySpecs::mergeWithProduct(
                $product,
                \App\Support\CompanyInventorySpecs::attributesFromRentmanRow($row, $linearUnitId, $weightUnitId)
            );

            Equipment::create(array_merge([
                'user_id' => $user->id,
                'company_id' => $companyId,
                'product_id' => $product->id,
                'rentman_equipment_id' => $rentmanEquipmentId,
                'software_code' => $softwareCode,
                'quantity' => $quantity,
                'rental_price' => $rentalRate ?? 0,
                'description' => $description,
                'replacement_price' => null,
            ], $specAttributes));
            $equipment = Equipment::where('company_id', $companyId)
                ->where('rentman_equipment_id', $rentmanEquipmentId)
                ->first();
            if ($equipment) {
                RentmanInventoryImportService::appendEquipmentImagesFromRentman(
                    (int) $product->id,
                    $companyId,
                    $rentmanEquipmentId,
                    (int) $equipment->id,
                    (int) $user->id
                );
            }

            RentmanInventoryImportService::markRentmanCacheImported($companyId, $rentmanEquipmentId);

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
     * Search Rentman local cache for a marketplace inventory product (triggers catalog sync if needed).
     * POST /api/company-inventory/search-rentman-product
     */
    public function searchRentmanProduct(SearchRentmanProductRequest $request): JsonResponse
    {
        return $this->handleMarketplaceRentmanAction(
            (int) $request->input('company_inventory_id'),
            fn (RentmanIntegrationService $rentman, Equipment $equipment) => $rentman->searchRentmanProductForMarketplace($equipment),
            'Search Rentman product',
        );
    }

    /**
     * Confirm marketplace Rentman sync: link Equipment ID or create in Rentman when missing.
     * POST /api/company-inventory/confirm-rentman-sync
     */
    public function confirmRentmanSync(ConfirmRentmanSyncRequest $request): JsonResponse
    {
        $createIfMissing = (bool) $request->boolean('create_if_missing');
        $rentmanEquipmentId = $request->input('rentman_equipment_id');

        return $this->handleMarketplaceRentmanAction(
            (int) $request->input('company_inventory_id'),
            fn (RentmanIntegrationService $rentman, Equipment $equipment) => $rentman->confirmRentmanMarketplaceSync(
                $equipment,
                $rentmanEquipmentId !== null && $rentmanEquipmentId !== ''
                    ? (string) $rentmanEquipmentId
                    : null,
                $createIfMissing,
            ),
            'Confirm Rentman sync',
        );
    }

    /**
     * @param  callable(RentmanIntegrationService, Equipment): array  $action
     */
    private function handleMarketplaceRentmanAction(
        int $companyInventoryId,
        callable $action,
        string $logContext,
    ): JsonResponse {
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

        $equipment = Equipment::query()
            ->with(['product.brand'])
            ->whereKey($companyInventoryId)
            ->first();

        if (!$equipment || (int) $equipment->company_id !== (int) $companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Company inventory record not found for your company.',
            ], 404);
        }

        $rentman = RentmanIntegrationService::forProviderCompany((int) $companyId);
        if (!$rentman) {
            return response()->json([
                'success' => false,
                'action' => 'error',
                'message' => 'Rentman integration is not configured for this company. Please add API credentials before syncing with Rentman.',
            ], 422);
        }

        try {
            $result = $action($rentman, $equipment);

            $status = match ($result['action'] ?? '') {
                'duplicate_resource' => 409,
                'error' => 500,
                default => ($result['success'] ?? false) ? 200 : 422,
            };

            return response()->json($result, $status);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'action' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            Log::error($logContext . ' failed', [
                'company_id' => $companyId,
                'company_inventory_id' => $equipment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'action' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        } catch (\Throwable $e) {
            Log::error($logContext . ' unexpected error', [
                'company_id' => $companyId,
                'company_inventory_id' => $equipment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'action' => 'error',
                'message' => 'An unexpected error occurred while processing the Rentman sync request.',
            ], 500);
        }
    }

    protected function getAuthenticatedUser()
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function assertRentmanMandatoryFields(RentmanEquipment $row): void
    {
        $missing = [];
        if ($row->height === null) {
            $missing[] = 'height';
        }
        if ($row->width === null) {
            $missing[] = 'width';
        }
        if ($row->length === null) {
            $missing[] = 'length';
        }
        if ($row->weight === null) {
            $missing[] = 'weight';
        }
        if ($row->country_of_origin === null || trim((string) $row->country_of_origin) === '') {
            $missing[] = 'country_of_origin';
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'Rentman equipment details are incomplete. Missing required fields: ' . implode(', ', $missing) . '.'
            );
        }
    }
}
