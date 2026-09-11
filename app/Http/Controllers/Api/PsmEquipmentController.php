<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPsmEquipmentsRequest;
use App\Models\Product;
use App\Services\InventoryImportService;
use App\Services\InventoryMaster\PsmEquipmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class PsmEquipmentController extends Controller
{
    public function __construct(
        private readonly PsmEquipmentService $psmEquipmentService,
    ) {}

    /**
     * GET /api/psm-equipments
     *
     * Paginated inventory_master listing for the PSM Equipments frontend.
     */
    public function index(ListPsmEquipmentsRequest $request): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? config('app.admin_list_per_page', 25));
        $search = $validated['search'] ?? null;

        $paginator = $this->psmEquipmentService->paginateCatalog($search, $perPage);

        $importedIds = [];
        if ($user?->company_id) {
            $importedIds = $this->psmEquipmentService->importedProductIdsForCompany(
                (int) $user->company_id,
                $paginator->getCollection()->pluck('id')->all()
            );
        }

        $data = $paginator->getCollection()
            ->map(fn (Product $product) => $this->psmEquipmentService->formatListingItem(
                $product,
                in_array((int) $product->id, $importedIds, true)
            ))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'PSM equipments fetched successfully.',
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/psm-equipments/{id}
     *
     * Full inventory_master details and catalog images.
     */
    public function show(int $id): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();

        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $alreadyImported = false;
        if ($user?->company_id) {
            $alreadyImported = InventoryImportService::findExistingInventoryForProduct(
                (int) $user->company_id,
                (int) $product->id
            ) !== null;
        }

        return response()->json([
            'success' => true,
            'message' => 'PSM equipment details fetched successfully.',
            'data' => $this->psmEquipmentService->formatDetails($product, $alreadyImported),
        ]);
    }

    /**
     * POST /api/psm-equipments/{id}/import
     *
     * Import inventory_master into the authenticated user's company_inventory.
     */
    public function import(int $id): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (! $user?->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any company.',
            ], 404);
        }

        if (strtolower((string) $user->account_type) !== 'provider') {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'ACCOUNT_NOT_PROVIDER',
                    'message' => 'Only provider accounts are allowed to perform this action.',
                ],
            ], 403);
        }

        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        try {
            $result = InventoryImportService::importMasterProductToCompany(
                (int) $user->company_id,
                (int) $user->id,
                (int) $product->id
            );
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('PsmEquipmentController: import failed', [
                'product_id' => $id,
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to import equipment.',
            ], 500);
        }

        if ($result['already_exists']) {
            $existing = $result['equipment'];

            return response()->json([
                'success' => false,
                'message' => 'This equipment already exists for your company. Duplicate entries are not allowed.',
                'data' => [
                    'existing_equipment_id' => $existing->id,
                    'product_id' => $existing->product_id,
                ],
            ], 409);
        }

        $equipment = $result['equipment'];

        return response()->json([
            'success' => true,
            'message' => 'PSM equipment imported into company inventory.',
            'data' => [
                'equipment_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'company_id' => $equipment->company_id,
                'quantity' => $equipment->quantity,
            ],
        ], 201);
    }
}
