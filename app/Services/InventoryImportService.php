<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Product;
use App\Support\CompanyInventorySpecs;
use App\Support\InventoryImageSyncService;
use App\Support\InventoryMeasurementUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * @return array{status: string, flex?: array, inventory_id?: int, product_id?: int, day_rate?: float|null, message?: string}
     */
    public static function checkImportStatus(int $companyId, string $flexId): array
    {
        $existingByFlex = Equipment::where('company_id', $companyId)
            ->where('flex_resource_id', $flexId)
            ->with('product.brand')
            ->first();

        if ($existingByFlex) {
            $product = $existingByFlex->product;
            $flex = self::buildFlexImportCheckExtras($companyId, $flexId);

            return [
                'status' => 'already_in_inventory',
                'message' => 'Already imported',
                'brand_name' => $product?->brand?->name ?? null,
                'model' => $product?->model ?? null,
                'flex' => $flex,
                'psm' => CompanyInventorySpecs::importCheckPsmPayload($product, $existingByFlex),
            ];
        }

        $details = FlexService::getInventoryDetails($companyId, $flexId);
        $rentalQty = FlexService::getRentalQtySummary($companyId, $flexId);
        $flex = FlexService::flexImportCheckFlexPayload($details, $rentalQty);

        $proSubFields = FlexService::getProSubrentalMarketplaceCustomFields($companyId, $flexId);
        if ($proSubFields !== null) {
            $flex['publish_to_psm'] = $proSubFields['publish_to_psm'] ?? null;
            if (!empty(trim((string) ($proSubFields['psm_code'] ?? '')))) {
                $flex['flex_psm_code'] = trim((string) $proSubFields['psm_code']);
            }
        }

        $existingProduct = FlexService::matchUsingPSMCode($companyId, $flexId, $proSubFields);
        if (!$existingProduct) {
            $name = $details['name'] ?? '';
            $parsed = FlexService::parseBrandAndModel($name);
            $existingProduct = FlexService::findExistingProduct($parsed['brand_id'], $parsed['normalized_model'], $name);
        } else {
            Log::debug('Inventory import check: using PSM Code custom-field match (skipping brand/model match)', [
                'flex_id' => $flexId,
                'company_id' => $companyId,
                'product_id' => $existingProduct->id,
            ]);
        }
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
                    'flex' => $flex,
                    'psm' => CompanyInventorySpecs::importCheckPsmPayload($existingProduct, $existingInventory),
                ];
            }

            return [
                'status' => 'product_exists',
                'product_id' => $existingProduct->id,
                'day_rate' => $dayRate,
                'brand_name' => $brandName,
                'model' => $model,
                'flex' => $flex,
                'psm' => CompanyInventorySpecs::importCheckPsmPayload($existingProduct),
            ];
        }

        return [
            'status' => 'new_product',
            'day_rate' => $dayRate,
            'flex' => $flex,
        ];
    }

    /**
     * Flex name/sku/part/qty for import-check when getInventoryDetails may be called alone (already imported branch).
     *
     * @return array{name: string|null, sku: string|null, part_number: string|null, rental_qty_on_hand: int|null, rental_qty_allocated: int|null}
     */
    protected static function buildFlexImportCheckExtras(int $companyId, string $flexId): array
    {
        try {
            $details = FlexService::getInventoryDetails($companyId, $flexId);
            $rentalQty = FlexService::getRentalQtySummary($companyId, $flexId);

            return FlexService::flexImportCheckFlexPayload($details, $rentalQty);
        } catch (\Throwable $e) {
            return FlexService::flexImportCheckFlexPayload([], [
                'qty_on_hand' => null,
                'qty_allocated' => null,
            ]);
        }
    }

    /**
     * Store Flex images on master catalog and copy missing paths to company_inventory.
     */
    public static function appendEquipmentImagesFromFlex(
        int $inventoryMasterId,
        int $equipmentId,
        array $imageUrls,
        ?int $userId = null
    ): void {
        InventoryImageSyncService::importUrlsToMasterAndEquipment(
            $inventoryMasterId,
            $equipmentId,
            $imageUrls,
            'flex',
            $userId
        );
    }

    /**
     * Import using an explicit inventory_master id: link to an unlinked row, create a new row, or update if already linked.
     *
     * @throws \RuntimeException On duplicate Flex for another product or link failure
     */
    public static function importFlexWithExplicitProductId(
        int $companyId,
        int $userId,
        int $productId,
        string $flexId,
        int $quantity,
        ?float $rentalOverride,
        ?string $softwareCode,
        array $imageUrls = []
    ): Equipment {
        $existingByFlex = Equipment::where('company_id', $companyId)
            ->where('flex_resource_id', $flexId)
            ->first();

        if ($existingByFlex) {
            if ((int) $existingByFlex->product_id !== (int) $productId) {
                throw new \RuntimeException('This Flex resource is already imported for this company.');
            }
            $details = FlexService::getInventoryDetails($companyId, $flexId);
            $linearUnitId = InventoryMeasurementUnits::resolveLinearUnitIdFromFlexName($details['linearUnit'] ?? null);
            $weightUnitId = InventoryMeasurementUnits::resolveWeightUnitIdFromFlexName($details['weightUnit'] ?? null);
            $product = Product::find($productId);
            if ($product) {
                self::updateProductSpecsFromFlexIfEmpty($product, $details, $linearUnitId, $weightUnitId);
            }

            $specPatch = $product
                ? CompanyInventorySpecs::patchFillEmpty(
                    $existingByFlex,
                    CompanyInventorySpecs::mergeWithProduct(
                        $product,
                        CompanyInventorySpecs::attributesFromFlexDetails($details, $linearUnitId, $weightUnitId)
                    )
                )
                : [];

            $existingByFlex->update(array_merge([
                'quantity' => $quantity,
                'rental_price' => $rentalOverride !== null ? $rentalOverride : $existingByFlex->rental_price,
            ], $specPatch));
            self::appendEquipmentImagesFromFlex($productId, (int) $existingByFlex->id, $imageUrls, $userId);

            return $existingByFlex->fresh();
        }

        Product::findOrFail($productId);

        $unlinked = Equipment::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where(function ($q) {
                $q->whereNull('flex_resource_id')->orWhere('flex_resource_id', '');
            })
            ->first();

        if ($unlinked) {
            $result = self::linkFlexToExistingInventory($companyId, $unlinked->id, $flexId, $quantity, $rentalOverride);
            if (!$result['success']) {
                throw new \RuntimeException($result['message']);
            }
            $equipment = Equipment::where('company_id', $companyId)
                ->where('flex_resource_id', $flexId)
                ->firstOrFail();
            self::appendEquipmentImagesFromFlex($productId, (int) $equipment->id, $imageUrls, $userId);

            return $equipment;
        }

        return FlexService::syncExistingProductWithFlexData(
            $companyId,
            $productId,
            $flexId,
            $softwareCode,
            $quantity,
            $userId,
            $imageUrls,
            $rentalOverride
        );
    }

    /**
     * Link an existing company_inventory record to a Flex resource.
     * Fetches Flex pricing and details, updates company_inventory and inventory_master.
     *
     * @param int $companyId Company ID
     * @param int $inventoryId company_inventory (Equipment) ID
     * @param string $flexId Flex resource ID
     * @param int|null $quantity When set, updates company_inventory.quantity
     * @param float|null $rentalOverride When set, uses this instead of Flex Day Rate
     * @return array{success: bool, status?: string, message: string}
     */
    public static function linkFlexToExistingInventory(
        int $companyId,
        int $inventoryId,
        string $flexId,
        ?int $quantity = null,
        ?float $rentalOverride = null
    ): array {
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

        // If this inventory already has this flex_id, allow quantity / rental updates when provided
        if ($inventory->flex_resource_id === $flexId) {
            if ($quantity !== null || $rentalOverride !== null) {
                $details = FlexService::getInventoryDetails($companyId, $flexId);
                $linearUnitId = InventoryMeasurementUnits::resolveLinearUnitIdFromFlexName($details['linearUnit'] ?? null);
                $weightUnitId = InventoryMeasurementUnits::resolveWeightUnitIdFromFlexName($details['weightUnit'] ?? null);
                $product = Product::find($inventory->product_id);
                if ($product) {
                    self::updateProductSpecsFromFlexIfEmpty($product, $details, $linearUnitId, $weightUnitId);
                }

                $patch = [];
                if ($quantity !== null) {
                    $patch['quantity'] = $quantity;
                }
                if ($rentalOverride !== null) {
                    $patch['rental_price'] = $rentalOverride;
                }
                if ($product) {
                    $patch = array_merge(
                        $patch,
                        CompanyInventorySpecs::patchFillEmpty(
                            $inventory,
                            CompanyInventorySpecs::mergeWithProduct(
                                $product,
                                CompanyInventorySpecs::attributesFromFlexDetails($details, $linearUnitId, $weightUnitId)
                            )
                        )
                    );
                }
                if ($patch !== []) {
                    $inventory->update($patch);
                }

                return [
                    'success' => true,
                    'message' => 'Flex inventory updated',
                ];
            }

            return [
                'success' => false,
                'status' => 'already_linked',
                'message' => 'This inventory is already linked to this Flex resource.',
            ];
        }

        $service = new FlexService($companyId);

        // 1. Get Flex pricing (Day Rate) unless overridden
        $currencyId = FlexService::getUsdCurrencyId($companyId);
        $dayRate = $rentalOverride !== null
            ? $rentalOverride
            : ($currencyId ? $service->getDayRate($flexId, $currencyId) : null);

        // 2. Get Flex item details
        $details = FlexService::getInventoryDetails($companyId, $flexId);
        $replacementCost = $details['replacementCost'] ?? null;
        $linearUnitId = InventoryMeasurementUnits::resolveLinearUnitIdFromFlexName($details['linearUnit'] ?? null);
        $weightUnitId = InventoryMeasurementUnits::resolveWeightUnitIdFromFlexName($details['weightUnit'] ?? null);
        $sku = $details['sku'] ?? null;

        DB::beginTransaction();
        try {
            $product = Product::find($inventory->product_id);
            if ($product) {
                self::updateProductSpecsFromFlexIfEmpty($product, $details, $linearUnitId, $weightUnitId);
            }

            $specPatch = $product
                ? CompanyInventorySpecs::patchFillEmpty(
                    $inventory,
                    CompanyInventorySpecs::mergeWithProduct(
                        $product,
                        CompanyInventorySpecs::attributesFromFlexDetails($details, $linearUnitId, $weightUnitId)
                    )
                )
                : CompanyInventorySpecs::patchFillEmpty(
                    $inventory,
                    CompanyInventorySpecs::attributesFromFlexDetails($details, $linearUnitId, $weightUnitId)
                );

            // 3. Update company_inventory
            $inventoryUpdates = array_merge([
                'flex_resource_id' => $flexId,
                'rental_price' => $dayRate !== null ? $dayRate : $inventory->rental_price,
                'software_code' => $sku,
                'replacement_price' => $replacementCost !== null && $replacementCost !== '' ? (float) $replacementCost : $inventory->replacement_price,
            ], $specPatch);
            if ($quantity !== null) {
                $inventoryUpdates['quantity'] = $quantity;
            }
            $inventory->update($inventoryUpdates);

            $imageUrls = $details['imageUrls'] ?? [];
            if (!empty($imageUrls) && $inventory->product_id) {
                InventoryImageSyncService::importUrlsToMasterAndEquipment(
                    (int) $inventory->product_id,
                    (int) $inventory->id,
                    $imageUrls,
                    'flex'
                );
            } elseif ($inventory->product_id) {
                InventoryImageSyncService::syncMasterToEquipment(
                    (int) $inventory->product_id,
                    (int) $inventory->id,
                    true
                );
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

    private static function updateProductSpecsFromFlexIfEmpty(
        Product $product,
        array $details,
        ?int $linearUnitId,
        ?int $weightUnitId
    ): void {
        $productUpdates = [];
        $mapping = [
            'height' => 'height',
            'width' => 'width',
            'length' => 'modelLength',
            'weight' => 'weight',
        ];

        foreach ($mapping as $dbField => $flexKey) {
            if ($product->{$dbField} !== null && $product->{$dbField} !== '') {
                continue;
            }
            $value = $details[$flexKey] ?? null;
            if ($value !== null && $value !== '') {
                $productUpdates[$dbField] = $value;
            }
        }

        $replacementCost = $details['replacementCost'] ?? null;
        if (
            $product->replacement_price === null
            && $replacementCost !== null
            && $replacementCost !== ''
            && (float) $replacementCost > 0
        ) {
            $productUpdates['replacement_price'] = (float) $replacementCost;
        }

        if ($product->linear_unit_id === null && $linearUnitId !== null) {
            $productUpdates['linear_unit_id'] = $linearUnitId;
        }
        if ($product->weight_unit_id === null && $weightUnitId !== null) {
            $productUpdates['weight_unit_id'] = $weightUnitId;
        }

        if ($productUpdates !== []) {
            $product->update($productUpdates);
        }
    }
}
