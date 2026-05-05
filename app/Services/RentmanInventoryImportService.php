<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Product;
use App\Models\RentmanEquipment;
use Illuminate\Support\Facades\DB;

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
        ?string $softwareCode
    ): Equipment {
        $existingByRentman = Equipment::where('company_id', $companyId)
            ->where('rentman_equipment_id', $rentmanId)
            ->first();

        if ($existingByRentman) {
            if ((int) $existingByRentman->product_id !== (int) $productId) {
                throw new \RuntimeException('This Rentman equipment is already imported for this company.');
            }
            $existingByRentman->update([
                'quantity' => $quantity,
                'rental_price' => $rentalOverride !== null ? $rentalOverride : $existingByRentman->rental_price,
            ]);
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
            $result = self::linkRentmanToExistingInventory($companyId, $unlinked->id, $rentmanId, $quantity, $rentalOverride);
            if (!$result['success']) {
                throw new \RuntimeException($result['message']);
            }
            self::markRentmanCacheImported($companyId, $rentmanId);

            return Equipment::where('company_id', $companyId)
                ->where('rentman_equipment_id', $rentmanId)
                ->firstOrFail();
        }

        $equipment = RentmanService::syncExistingProductWithRentmanData(
            $companyId,
            $productId,
            $rentmanId,
            $softwareCode,
            $quantity,
            $userId,
            $rentalOverride
        );
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
            if ($quantity !== null || $rentalOverride !== null) {
                $patch = [];
                if ($quantity !== null) {
                    $patch['quantity'] = $quantity;
                }
                if ($rentalOverride !== null) {
                    $patch['rental_price'] = $rentalOverride;
                }
                if ($patch !== []) {
                    $inventory->update($patch);
                }
            }

            if ($inventory->rentman_equipment_id === $rentmanId && $quantity === null && $rentalOverride === null) {
                return [
                    'success' => false,
                    'status' => 'already_linked',
                    'message' => 'This inventory is already linked to this Rentman equipment.',
                ];
            }

            self::markRentmanCacheImported($companyId, $rentmanId);

            return [
                'success' => true,
                'message' => 'Rentman inventory updated',
            ];
        }

        DB::beginTransaction();
        try {
            $inventoryUpdates = [
                'rentman_equipment_id' => $rentmanId,
                'software_code' => $code !== '' ? $code : ($inventory->software_code ?? $rentmanId),
            ];
            if ($quantity !== null) {
                $inventoryUpdates['quantity'] = $quantity;
            }
            if ($rentalOverride !== null) {
                $inventoryUpdates['rental_price'] = $rentalOverride;
            }
            $inventory->update($inventoryUpdates);

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
}
