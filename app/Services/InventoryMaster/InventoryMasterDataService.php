<?php

namespace App\Services\InventoryMaster;

use App\Models\Equipment;
use App\Models\Product;
use App\Support\CompanyInventorySpecs;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class InventoryMasterDataService
{
    /**
     * @return array<string, mixed>
     */
    public function fetchByProductId(int $productId): array
    {
        $product = Product::query()
            ->with(['linearUnit:id,code,name', 'weightUnit:id,code,name'])
            ->find($productId);

        if (!$product) {
            Log::warning('InventoryMasterDataService: product not found', [
                'product_id' => $productId,
            ]);

            throw (new ModelNotFoundException())->setModel(Product::class, [$productId]);
        }

        Log::info('InventoryMasterDataService: fetched inventory master data by product_id', [
            'product_id' => $productId,
        ]);

        return $this->buildResponse($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchByEquipmentId(int $equipmentId, int $companyId): array
    {
        $equipment = Equipment::query()->find($equipmentId);

        if (!$equipment || (int) $equipment->company_id !== $companyId) {
            Log::warning('InventoryMasterDataService: equipment not found or access denied', [
                'equipment_id' => $equipmentId,
                'company_id' => $companyId,
                'equipment_company_id' => $equipment?->company_id,
            ]);

            throw (new ModelNotFoundException())->setModel(Equipment::class, [$equipmentId]);
        }

        if (!$equipment->product_id) {
            Log::warning('InventoryMasterDataService: equipment has no linked product', [
                'equipment_id' => $equipmentId,
            ]);

            throw (new ModelNotFoundException())->setModel(Product::class, []);
        }

        $product = Product::query()
            ->with(['linearUnit:id,code,name', 'weightUnit:id,code,name'])
            ->find($equipment->product_id);

        if (!$product) {
            Log::warning('InventoryMasterDataService: linked inventory master product not found', [
                'equipment_id' => $equipmentId,
                'product_id' => $equipment->product_id,
            ]);

            throw (new ModelNotFoundException())->setModel(Product::class, [$equipment->product_id]);
        }

        Log::info('InventoryMasterDataService: fetched inventory master data by equipment_id', [
            'equipment_id' => $equipmentId,
            'product_id' => $product->id,
            'company_id' => $companyId,
        ]);

        return $this->buildResponse($product, $equipmentId);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildResponse(Product $product, ?int $equipmentId = null): array
    {
        $specs = CompanyInventorySpecs::productSpecsForJson($product);

        return [
            'product_id' => $product->id,
            'equipment_id' => $equipmentId,
            'psm_code' => $product->psm_code,
            'dimensions' => [
                'height' => $this->nullableFloat($specs['height'] ?? null),
                'width' => $this->nullableFloat($specs['width'] ?? null),
                'length' => $this->nullableFloat($specs['length'] ?? null),
                'weight' => $this->nullableFloat($specs['weight'] ?? null),
                'linear_unit_id' => $specs['linear_unit_id'] ?? null,
                'weight_unit_id' => $specs['weight_unit_id'] ?? null,
                'linear_unit' => $product->linearUnit ? [
                    'id' => $product->linearUnit->id,
                    'code' => $product->linearUnit->code,
                    'name' => $product->linearUnit->name,
                ] : null,
                'weight_unit' => $product->weightUnit ? [
                    'id' => $product->weightUnit->id,
                    'code' => $product->weightUnit->code,
                    'name' => $product->weightUnit->name,
                ] : null,
                'dimensions_display' => $specs['dimensions_display'] ?? null,
                'weight_display' => $specs['weight_display'] ?? null,
            ],
            'country_of_origin' => $specs['country_of_origin'] ?? null,
            'iso_code_2' => $product->iso_code_2,
            'iso_code_3' => $product->iso_code_3,
            'hsn_code' => $specs['hsn_code'] ?? null,
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
