<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentImage;
use App\Models\LinearUnit;
use App\Models\Product;
use App\Models\RentmanEquipment;
use App\Support\CompanyInventorySpecs;
use App\Support\InventoryMeasurementUnits;
use App\Models\WeightUnit;
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
                ];
            }

            return [
                'status' => 'product_exists',
                'product_id' => $existingProduct->id,
                'day_rate' => null,
                'brand_name' => $brandName,
                'model' => $model,
                'rentman' => $rentman,
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

    public static function appendEquipmentImagesFromRentman(int $companyId, string $rentmanId, int $equipmentId): void
    {
        try {
            $imageUrls = RentmanService::getEquipmentImageUrls($companyId, $rentmanId);
        } catch (\Throwable $e) {
            Log::warning('Rentman images fetch failed during import/link', [
                'company_id' => $companyId,
                'rentman_id' => $rentmanId,
                'equipment_id' => $equipmentId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($imageUrls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            if (EquipmentImage::where('equipment_id', $equipmentId)->where('image_path', $url)->exists()) {
                continue;
            }
            EquipmentImage::create([
                'equipment_id' => $equipmentId,
                'image_path' => $url,
            ]);
        }
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
            self::appendEquipmentImagesFromRentman($companyId, $rentmanId, (int) $existingByRentman->id);
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
            self::appendEquipmentImagesFromRentman($companyId, $rentmanId, (int) $equipment->id);

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
        self::appendEquipmentImagesFromRentman($companyId, $rentmanId, (int) $equipment->id);
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
            self::appendEquipmentImagesFromRentman($companyId, $rentmanId, (int) $inventory->id);

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

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        self::markRentmanCacheImported($companyId, $rentmanId);
        self::appendEquipmentImagesFromRentman($companyId, $rentmanId, (int) $inventory->id);

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
            $configuredLinearId = config('services.rentman.default_linear_unit_id');
            if (is_numeric($configuredLinearId)) {
                $configuredLinearId = (int) $configuredLinearId;
                if (!LinearUnit::whereKey($configuredLinearId)->exists()) {
                    Log::error('Invalid Rentman linear unit configuration', [
                        'configured_linear_unit_id' => $configuredLinearId,
                    ]);
                    throw new \RuntimeException(
                        'Invalid Rentman configuration: RENTMAN_DEFAULT_LINEAR_UNIT_ID (' . $configuredLinearId . ') does not exist in linear_units.'
                    );
                }
                $productUpdates['linear_unit_id'] = $configuredLinearId;
            } else {
                $linearUnit = LinearUnit::whereRaw('LOWER(name) = ?', ['inch'])->first()
                    ?: LinearUnit::whereRaw('LOWER(name) = ?', ['inches'])->first()
                    ?: LinearUnit::whereRaw('LOWER(name) = ?', ['in'])->first();
                if ($linearUnit) {
                    $productUpdates['linear_unit_id'] = $linearUnit->id;
                }
            }
        }
        if ($product->weight_unit_id === null) {
            $configuredWeightId = config('services.rentman.default_weight_unit_id');
            if (is_numeric($configuredWeightId)) {
                $configuredWeightId = (int) $configuredWeightId;
                if (!WeightUnit::whereKey($configuredWeightId)->exists()) {
                    Log::error('Invalid Rentman weight unit configuration', [
                        'configured_weight_unit_id' => $configuredWeightId,
                    ]);
                    throw new \RuntimeException(
                        'Invalid Rentman configuration: RENTMAN_DEFAULT_WEIGHT_UNIT_ID (' . $configuredWeightId . ') does not exist in weight_units.'
                    );
                }
                $productUpdates['weight_unit_id'] = $configuredWeightId;
            } else {
                $weightUnit = WeightUnit::whereRaw('LOWER(name) = ?', ['pound'])->first()
                    ?: WeightUnit::whereRaw('LOWER(name) = ?', ['pounds'])->first()
                    ?: WeightUnit::whereRaw('LOWER(name) = ?', ['lbs'])->first()
                    ?: WeightUnit::whereRaw('LOWER(name) = ?', ['lb'])->first();
                if ($weightUnit) {
                    $productUpdates['weight_unit_id'] = $weightUnit->id;
                }
            }
        }

        if ($productUpdates !== []) {
            $product->update($productUpdates);
        }
    }
}
