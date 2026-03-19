<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Log;

class InventoryImportService
{
    /**
     * Check if company already has inventory for the given product (inventory_master).
     *
     * @param int $companyId Company ID
     * @param int $productId inventory_master (Product) ID
     * @return Equipment|null
     */
    public static function findExistingInventoryForProduct(int $companyId, int $productId): ?Equipment
    {
        return Equipment::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->first();
    }

    /**
     * Check import status for a Flex product. Used by checkImport and import endpoints.
     *
     * @param int $companyId Company ID
     * @param string $flexId Flex resource ID
     * @return array{status: string, inventory_id?: int, product_id?: int, day_rate?: float|null, message?: string}
     */
    public static function checkImportStatus(int $companyId, string $flexId): array
    {
        $existingByFlex = Equipment::where('company_id', $companyId)
            ->where('flex_resource_id', $flexId)
            ->with('product.brand')
            ->first();

        if ($existingByFlex) {
            $product = $existingByFlex->product;
            return [
                'status' => 'already_in_inventory',
                'message' => 'Already imported',
                'brand_name' => $product?->brand?->name ?? null,
                'model' => $product?->model ?? null,
            ];
        }

        $details = FlexService::getInventoryDetails($companyId, $flexId);
        $name = $details['name'] ?? '';
        $parsed = FlexService::parseBrandAndModel($name);
        $existingProduct = FlexService::findExistingProduct($parsed['brand_id'], $parsed['normalized_model'], $name);
        $dayRate = FlexService::getDayRentalRate($companyId, $flexId);

        if ($existingProduct) {
            $existingProduct->load('brand');
            $brandName = $existingProduct->brand->name ?? null;
            $model = $existingProduct->model ?? null;

            $existingInventory = self::findExistingInventoryForProduct($companyId, $existingProduct->id);

            if ($existingInventory) {
                return [
                    'status' => 'inventory_exists',
                    'inventory_id' => $existingInventory->id,
                    'brand_name' => $brandName,
                    'model' => $model,
                ];
            }

            return [
                'status' => 'product_exists',
                'product_id' => $existingProduct->id,
                'day_rate' => $dayRate,
                'brand_name' => $brandName,
                'model' => $model,
            ];
        }

        return [
            'status' => 'new_product',
            'day_rate' => $dayRate,
        ];
    }

    /**
     * Link an existing company_inventory record to a Flex resource.
     * Fetches Flex pricing and details, updates company_inventory and inventory_master.
     *
     * @param int $companyId Company ID
     * @param int $inventoryId company_inventory (Equipment) ID
     * @param string $flexId Flex resource ID
     * @return array{success: bool, status?: string, message: string}
     */
    public static function linkFlexToExistingInventory(int $companyId, int $inventoryId, string $flexId): array
    {
        $inventory = Equipment::where('id', $inventoryId)
            ->where('company_id', $companyId)
            ->first();

        if (!$inventory) {
            return [
                'success' => false,
                'message' => 'Inventory not found or does not belong to this company.',
            ];
        }

        // Duplicate safety: check if this flex_id is already linked to any inventory in this company
        $alreadyLinked = Equipment::where('company_id', $companyId)
            ->where('flex_resource_id', $flexId)
            ->where('id', '!=', $inventoryId)
            ->exists();

        if ($alreadyLinked) {
            return [
                'success' => false,
                'status' => 'already_linked',
                'message' => 'This Flex resource is already linked to another inventory item.',
            ];
        }

        // If this inventory already has this flex_id, consider it already linked
        if ($inventory->flex_resource_id === $flexId) {
            return [
                'success' => false,
                'status' => 'already_linked',
                'message' => 'This inventory is already linked to this Flex resource.',
            ];
        }

        $service = new FlexService($companyId);

        // 1. Get Flex pricing (Day Rate)
        $currencyId = FlexService::getUsdCurrencyId($companyId);
        $dayRate = $currencyId ? $service->getDayRate($flexId, $currencyId) : null;
Log::info("day rate price".$dayRate);
        // 2. Get Flex item details
        $details = FlexService::getInventoryDetails($companyId, $flexId);
        $replacementCost = $details['replacementCost'] ?? null;
        $height = $details['height'] ?? null;
        $width = $details['width'] ?? null;
        $length = $details['modelLength'] ?? null;
        $weight = $details['weight'] ?? null;
        $sku =  $details['sku'] ?? null;

        DB::beginTransaction();
        try {
            // 3. Update company_inventory (always update rental_price with Flex Day Rate when available)
            $inventory->update([
                'flex_resource_id' => $flexId,
                'rental_price' => $dayRate !== null ? $dayRate : $inventory->rental_price,
                'software_code' => $sku,
                'replacement_price' => $replacementCost !== null && $replacementCost !== '' ? (float) $replacementCost : $inventory->replacement_price,
            ]);

            // 4. Update inventory_master ONLY if values are empty
            $product = Product::find($inventory->product_id);
            if ($product) {
                $productUpdates = [];
                if ($product->height === null && $height !== null && $height !== '') {
                    $productUpdates['height'] = $height;
                }
                if ($product->width === null && $width !== null && $width !== '') {
                    $productUpdates['width'] = $width;
                }
                if ($product->length === null && $length !== null && $length !== '') {
                    $productUpdates['length'] = $length;
                }
                if ($product->weight === null && $weight !== null && $weight !== '') {
                    $productUpdates['weight'] = $weight;
                }
                if ($product->replacement_price === null && $replacementCost !== null && $replacementCost !== '' && (float) $replacementCost > 0) {
                    $productUpdates['replacement_price'] = (float) $replacementCost;
                }
                if (!empty($productUpdates)) {
                    $product->update($productUpdates);
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Flex linked successfully',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
