<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Product;
use App\Models\RentmanEquipment;
use App\Support\CompanyInventorySpecs;
use App\Support\InventoryImageSyncService;
use App\Support\InventoryMeasurementUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RentmanInventoryImportService
{
    /**
     * @return array<string, mixed>
     */
    public static function checkImportStatus(int $companyId, string $rentmanId): array
    {
        $existingByRentman = Equipment::where('company_id', $companyId)
            ->where('rentman_equipment_id', $rentmanId)
            ->with('product.brand')
            ->first();

        $row = RentmanEquipment::where('company_id', $companyId)
            ->where('rentman_id', $rentmanId)
            ->first();

        if (!$row) {
            throw new \RuntimeException(
                'Rentman equipment not found locally. Run a sync first, then try again.'
            );
        }

        $row = RentmanService::fetchAndStoreEquipmentDetails($companyId, $rentmanId);
        $rentman = RentmanService::importCheckRentmanPayload($row);

        if ($existingByRentman) {
            $product = $existingByRentman->product;

            return [
                'status' => 'already_in_inventory',
                'message' => 'Already imported',
                'brand_name' => $product?->brand?->name ?? null,
                'model' => $product?->model ?? null,
                'rentman' => $rentman,
                'psm' => CompanyInventorySpecs::importCheckPsmPayload($product, $existingByRentman),
            ];
        }

        $label = RentmanService::primaryLabel($row);
        $parsed = FlexService::parseBrandAndModel($label);
        $existingProduct = FlexService::findExistingProduct(
            $parsed['brand_id'],
            $parsed['normalized_model'],
            $label
        );

        if ($existingProduct) {
            $existingProduct->load('brand');
            $brandName = $existingProduct->brand->name ?? null;
            $model = $existingProduct->model ?? null;

            $existingInventory = Equipment::where('company_id', $companyId)
                ->where('product_id', $existingProduct->id)
                ->first();

            if ($existingInventory) {
                return [
                    'status' => 'inventory_exists',
                    'inventory_id' => $existingInventory->id,
                    'brand_name' => $brandName,
                    'model' => $model,
                    'rentman' => $rentman,
                    'psm' => CompanyInventorySpecs::importCheckPsmPayload($existingProduct, $existingInventory),
                ];
            }

            return [
                'status' => 'product_exists',
                'product_id' => $existingProduct->id,
                'day_rate' => null,
                'brand_name' => $brandName,
                'model' => $model,
                'rentman' => $rentman,
                'psm' => CompanyInventorySpecs::importCheckPsmPayload($existingProduct),
            ];
        }

        return [
            'status' => 'new_product',
            'day_rate' => null,
            'rentman' => $rentman,
        ];
    }

    public static function markRentmanCacheImported(int $companyId, string $rentmanId): void
    {
        RentmanEquipment::where('company_id', $companyId)
            ->where('rentman_id', $rentmanId)
            ->update([
                'is_imported' => true,
                'imported_at' => now(),
            ]);
    }

    /**
     * Allow re-import after company_inventory linked to this Rentman item is removed.
     */
    public static function unmarkRentmanCacheImported(int $companyId, string $rentmanId): void
    {
        $rentmanId = trim($rentmanId);
        if ($rentmanId === '') {
            return;
        }

        RentmanEquipment::where('company_id', $companyId)
            ->where('rentman_id', $rentmanId)
            ->update([
                'is_imported' => false,
                'imported_at' => null,
            ]);
    }

    public static function appendEquipmentImagesFromRentman(
        int $inventoryMasterId,
        int $companyId,
        string $rentmanId,
        int $equipmentId,
        ?int $userId = null
    ): void {
        try {
            $imageUrls = RentmanService::getEquipmentImageUrls($companyId, $rentmanId);
        } catch (\Throwable $e) {
            Log::warning('Rentman images fetch failed during import/link', [
                'company_id' => $companyId,
                'rentman_id' => $rentmanId,
                'equipment_id' => $equipmentId,
                'error' => $e->getMessage(),
            ]);

            InventoryImageSyncService::syncMasterToEquipment($inventoryMasterId, $equipmentId, true);

            return;
        }

        InventoryImageSyncService::importUrlsToMasterAndEquipment(
            $inventoryMasterId,
            $equipmentId,
            $imageUrls,
            'rentman',
            $userId
        );
    }

    /**
     * @throws \RuntimeException
     */
    public static function importRentmanWithExplicitProductId(
        int $companyId,
        int $userId,
        int $productId,
        string $rentmanId,
        int $quantity,
        ?float $rentalOverride,
        ?string $softwareCode,
        ?string $description = null
    ): Equipment {
        $product = Product::findOrFail($productId);
        $row = RentmanEquipment::where('company_id', $companyId)
            ->where('rentman_id', $rentmanId)
            ->first();

        if ($row) {
            self::updateProductSpecsIfEmpty($product, $row);
        }

        $existingByRentman = Equipment::where('company_id', $companyId)
            ->where('rentman_equipment_id', $rentmanId)
            ->first();

        if ($existingByRentman) {
            $resolvedSoftwareCode = trim((string) ($softwareCode ?? ''));
            if ($resolvedSoftwareCode === '') {
                $resolvedSoftwareCode = trim((string) ($row?->code ?? ''));
            }
            if ($resolvedSoftwareCode === '') {
                $resolvedSoftwareCode = $rentmanId;
            }

            if ((int) $existingByRentman->product_id !== (int) $productId) {
                throw new \RuntimeException('This Rentman equipment is already imported for this company.');
            }
            $linearUnitId = InventoryMeasurementUnits::resolveRentmanLinearUnitId();
            $weightUnitId = InventoryMeasurementUnits::resolveRentmanWeightUnitId();
            $specPatch = $row
                ? CompanyInventorySpecs::patchFillEmpty(
                    $existingByRentman,
                    CompanyInventorySpecs::mergeWithProduct(
                        $product,
                        CompanyInventorySpecs::attributesFromRentmanRow($row, $linearUnitId, $weightUnitId)
                    )
                )
                : CompanyInventorySpecs::patchFillEmpty(
                    $existingByRentman,
                    CompanyInventorySpecs::attributesFromProduct($product)
                );

            $existingByRentman->update(array_merge([
                'quantity' => $quantity,
                'rental_price' => $rentalOverride !== null ? $rentalOverride : $existingByRentman->rental_price,
                'software_code' => $resolvedSoftwareCode,
                'description' => $description ?? $existingByRentman->description,
            ], $specPatch));
            self::appendEquipmentImagesFromRentman($productId, $companyId, $rentmanId, (int) $existingByRentman->id, $userId);
            self::markRentmanCacheImported($companyId, $rentmanId);

            return $existingByRentman->fresh();
        }

        Product::findOrFail($productId);

        $unlinked = Equipment::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where(function ($q) {
                $q->whereNull('rentman_equipment_id')->orWhere('rentman_equipment_id', '');
            })
            ->first();

        if ($unlinked) {
            $result = self::linkRentmanToExistingInventory(
                $companyId,
                $unlinked->id,
                $rentmanId,
                $quantity,
                $rentalOverride,
                $description
            );
            if (!$result['success']) {
                throw new \RuntimeException($result['message']);
            }
            self::markRentmanCacheImported($companyId, $rentmanId);
            $equipment = Equipment::where('company_id', $companyId)
                ->where('rentman_equipment_id', $rentmanId)
                ->firstOrFail();
            self::appendEquipmentImagesFromRentman($productId, $companyId, $rentmanId, (int) $equipment->id, $userId);

            return $equipment;
        }

        $equipment = RentmanService::syncExistingProductWithRentmanData(
            $companyId,
            $productId,
            $rentmanId,
            $softwareCode,
            $quantity,
            $userId,
            $rentalOverride,
            $description
        );
        self::appendEquipmentImagesFromRentman($productId, $companyId, $rentmanId, (int) $equipment->id, $userId);
        self::markRentmanCacheImported($companyId, $rentmanId);

        return $equipment;
    }

    /**
     * @return array{success: bool, status?: string, message: string}
     */
    public static function linkRentmanToExistingInventory(
        int $companyId,
        int $inventoryId,
        string $rentmanId,
        ?int $quantity = null,
        ?float $rentalOverride = null,
        ?string $description = null
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

        $row = RentmanEquipment::where('company_id', $companyId)
            ->where('rentman_id', $rentmanId)
            ->first();

        if (!$row) {
            return [
                'success' => false,
                'message' => 'Rentman equipment not found locally. Run a sync first.',
            ];
        }

        $alreadyLinked = Equipment::where('company_id', $companyId)
            ->where('rentman_equipment_id', $rentmanId)
            ->where('id', '!=', $inventoryId)
            ->exists();

        if ($alreadyLinked) {
            return [
                'success' => false,
                'status' => 'already_linked',
                'message' => 'This Rentman equipment is already linked to another inventory item.',
            ];
        }

        $code = trim((string) ($row->code ?? ''));

        if ((string) $inventory->rentman_equipment_id === $rentmanId) {
            if ($quantity !== null || $rentalOverride !== null || $description !== null || $code !== '') {
                $product = Product::find($inventory->product_id);
                if ($product) {
                    self::updateProductSpecsIfEmpty($product, $row);
                }

                $patch = [];
                if ($quantity !== null) {
                    $patch['quantity'] = $quantity;
                }
                if ($rentalOverride !== null) {
                    $patch['rental_price'] = $rentalOverride;
                }
                if ($description !== null) {
                    $patch['description'] = $description;
                }
                if ($code !== '') {
                    $patch['software_code'] = $code;
                }
                if ($product) {
                    $linearUnitId = InventoryMeasurementUnits::resolveRentmanLinearUnitId();
                    $weightUnitId = InventoryMeasurementUnits::resolveRentmanWeightUnitId();
                    $patch = array_merge(
                        $patch,
                        CompanyInventorySpecs::patchFillEmpty(
                            $inventory,
                            CompanyInventorySpecs::mergeWithProduct(
                                $product,
                                CompanyInventorySpecs::attributesFromRentmanRow($row, $linearUnitId, $weightUnitId)
                            )
                        )
                    );
                }
                if ($patch !== []) {
                    $inventory->update($patch);
                }
            }

            if (
                $inventory->rentman_equipment_id === $rentmanId
                && $quantity === null
                && $rentalOverride === null
                && $description === null
                && $code === ''
            ) {
                return [
                    'success' => false,
                    'status' => 'already_linked',
                    'message' => 'This inventory is already linked to this Rentman equipment.',
                ];
            }

            self::markRentmanCacheImported($companyId, $rentmanId);
            if ($inventory->product_id) {
                self::appendEquipmentImagesFromRentman(
                    (int) $inventory->product_id,
                    $companyId,
                    $rentmanId,
                    (int) $inventory->id
                );
            }

            return [
                'success' => true,
                'message' => 'Rentman inventory updated',
            ];
        }

        DB::beginTransaction();
        try {
            $product = Product::find($inventory->product_id);
            if ($product && $row) {
                self::updateProductSpecsIfEmpty($product, $row);
            }

            $linearUnitId = InventoryMeasurementUnits::resolveRentmanLinearUnitId();
            $weightUnitId = InventoryMeasurementUnits::resolveRentmanWeightUnitId();
            $specPatch = $product
                ? CompanyInventorySpecs::patchFillEmpty(
                    $inventory,
                    CompanyInventorySpecs::mergeWithProduct(
                        $product,
                        CompanyInventorySpecs::attributesFromRentmanRow($row, $linearUnitId, $weightUnitId)
                    )
                )
                : CompanyInventorySpecs::patchFillEmpty(
                    $inventory,
                    CompanyInventorySpecs::attributesFromRentmanRow($row, $linearUnitId, $weightUnitId)
                );

            $inventoryUpdates = array_merge([
                'rentman_equipment_id' => $rentmanId,
                'software_code' => $code !== '' ? $code : ($inventory->software_code ?? $rentmanId),
            ], $specPatch);
            if ($quantity !== null) {
                $inventoryUpdates['quantity'] = $quantity;
            }
            if ($rentalOverride !== null) {
                $inventoryUpdates['rental_price'] = $rentalOverride;
            }
            if ($description !== null) {
                $inventoryUpdates['description'] = $description;
            }
            $inventory->update($inventoryUpdates);

            if ($inventory->product_id) {
                self::appendEquipmentImagesFromRentman(
                    (int) $inventory->product_id,
                    $companyId,
                    $rentmanId,
                    (int) $inventory->id
                );
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        self::markRentmanCacheImported($companyId, $rentmanId);

        return [
            'success' => true,
            'message' => 'Rentman linked successfully',
        ];
    }

    public static function updateProductSpecsIfEmpty(Product $product, RentmanEquipment $row): void
    {
        $productUpdates = [];
        if ($product->height === null && $row->height !== null) {
            $productUpdates['height'] = $row->height;
        }
        if ($product->width === null && $row->width !== null) {
            $productUpdates['width'] = $row->width;
        }
        if ($product->length === null && $row->length !== null) {
            $productUpdates['length'] = $row->length;
        }
        if ($product->weight === null && $row->weight !== null) {
            $productUpdates['weight'] = $row->weight;
        }
        if ($product->country_of_origin === null && $row->country_of_origin !== null) {
            $productUpdates['country_of_origin'] = $row->country_of_origin;
        }
        if ($product->linear_unit_id === null) {
            $linearUnitId = InventoryMeasurementUnits::resolveRentmanLinearUnitId();
            if ($linearUnitId !== null) {
                $productUpdates['linear_unit_id'] = $linearUnitId;
            }
        }
        if ($product->weight_unit_id === null) {
            $weightUnitId = InventoryMeasurementUnits::resolveRentmanWeightUnitId();
            if ($weightUnitId !== null) {
                $productUpdates['weight_unit_id'] = $weightUnitId;
            }
        }

        if ($productUpdates !== []) {
            $product->update($productUpdates);
        }
    }
}
