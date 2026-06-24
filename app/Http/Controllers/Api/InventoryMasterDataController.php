<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FetchInventoryMasterDataRequest;
use App\Services\InventoryMaster\InventoryMasterDataService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class InventoryMasterDataController extends Controller
{
    public function __construct(
        private readonly InventoryMasterDataService $inventoryMasterDataService,
    ) {
    }

    /**
     * GET /api/inventory-master/physical-details
     *
     * Fetch dimensions, country of origin, and HSN code from inventory_master.
     * Accepts either product_id or equipment_id (not both).
     */
    public function show(FetchInventoryMasterDataRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if (isset($validated['product_id'])) {
                $data = $this->inventoryMasterDataService->fetchByProductId((int) $validated['product_id']);
            } else {
                $user = JWTAuth::parseToken()->authenticate();

                if (!$user?->company_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User does not belong to any company.',
                    ], 404);
                }

                $data = $this->inventoryMasterDataService->fetchByEquipmentId(
                    (int) $validated['equipment_id'],
                    (int) $user->company_id,
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Inventory master data fetched successfully.',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Inventory master data not found.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('InventoryMasterDataController: failed to fetch inventory master data', [
                'product_id' => $request->input('product_id'),
                'equipment_id' => $request->input('equipment_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch inventory master data.',
            ], 500);
        }
    }
}
