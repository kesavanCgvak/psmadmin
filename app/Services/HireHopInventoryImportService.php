<?php

namespace App\Services;

use App\Exceptions\HireHopApiException;
use App\Models\Equipment;
use App\Models\Product;
use App\Support\CompanyInventorySpecs;
use App\Support\PsmCodeGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class HireHopInventoryImportService
{
    /**
     * @return array<string, mixed>
     */
    public static function checkImportStatus(int $companyId, string $stockId): array
    {
        $stockId = self::requireStockId($stockId);
        $existing = Equipment::query()
            ->where('company_id', $companyId)
            ->where('hirehop_stock_id', $stockId)
            ->with('product.brand')
            ->first();

        $remote = HireHopService::forCompany($companyId)->findRentalProduct($stockId);

        if ($existing) {
            $catalog = $existing->product;

            return [
                'status' => 'already_in_inventory',
                'message' => 'Already imported',
                'brand_name' => $catalog?->brand?->name,
                'model' => $catalog?->model,
                'inventory_id' => $existing->id,
                'product_id' => $existing->product_id,
                'hirehop' => self::hirehopPayload($remote, $stockId),
                'psm' => CompanyInventorySpecs::importCheckPsmPayload($catalog, $existing),
            ];
        }

        if ($remote === null) {
            throw new HireHopApiException('HireHop product was not found.', 404, 'HIREHOP_NOT_FOUND');
        }

        $name = (string) ($remote['name'] ?? '');
        $parsed = FlexService::parseBrandAndModel($name);
        $existingProduct = FlexService::findExistingProduct(
            $parsed['brand_id'],
            $parsed['normalized_model'],
            $name
        );
        $hirehop = self::hirehopPayload($remote, $stockId);

        if ($existingProduct) {
            $existingProduct->load('brand');
            $brandName = $existingProduct->brand->name ?? null;
            $model = $existingProduct->model ?? null;
            $existingInventory = InventoryImportService::findExistingInventoryForProduct($companyId, (int) $existingProduct->id);

            if ($existingInventory) {
                return [
                    'status' => 'inventory_exists',
                    'inventory_id' => $existingInventory->id,
                    'brand_name' => $brandName,
                    'model' => $model,
                    'hirehop' => $hirehop,
                    'psm' => CompanyInventorySpecs::importCheckPsmPayload($existingProduct, $existingInventory),
                ];
            }

            return [
                'status' => 'product_exists',
                'product_id' => $existingProduct->id,
                'day_rate' => null,
                'brand_name' => $brandName,
                'model' => $model,
                'hirehop' => $hirehop,
                'psm' => CompanyInventorySpecs::importCheckPsmPayload($existingProduct),
            ];
        }

        return [
            'status' => 'new_product',
            'day_rate' => null,
            'hirehop' => $hirehop,
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function import(
        int $companyId,
        int $userId,
        string $stockId,
        int $quantity,
        ?float $rentalOverride,
        bool $confirm,
        ?int $explicitProductId
    ): array {
        $stockId = self::requireStockId($stockId);
        $check = self::checkImportStatus($companyId, $stockId);
        $linkedToSameProduct = false;

        if ($check['status'] === 'already_in_inventory') {
            $linked = Equipment::query()
                ->where('company_id', $companyId)
                ->where('hirehop_stock_id', $stockId)
                ->first();
            $linkedToSameProduct = $explicitProductId !== null
                && $linked !== null
                && (int) $linked->product_id === $explicitProductId;

            if (!$linkedToSameProduct) {
                return [
                    'status' => 200,
                    'body' => [
                        'success' => false,
                        'status' => 'already_in_inventory',
                        'message' => $explicitProductId !== null
                            ? 'This HireHop product is already linked to a different product.'
                            : (string) ($check['message'] ?? 'Already imported'),
                    ],
                ];
            }
        }

        if (!$confirm) {
            if ($explicitProductId !== null) {
                return [
                    'status' => 200,
                    'body' => [
                        'status' => 'product_exists',
                        'product_id' => $explicitProductId,
                        'day_rate' => $rentalOverride,
                    ],
                ];
            }

            return [
                'status' => 200,
                'body' => [
                    'status' => 'new_product',
                    'day_rate' => $rentalOverride,
                    'hirehop' => $check['hirehop'] ?? null,
                ],
            ];
        }

        if ($explicitProductId !== null && !Product::query()->whereKey($explicitProductId)->exists()) {
            return [
                'status' => 404,
                'body' => [
                    'success' => false,
                    'message' => 'Product not found.',
                ],
            ];
        }

        $remote = is_array($check['hirehop'] ?? null) ? $check['hirehop'] : ['hirehop_stock_id' => $stockId];

        try {
            $equipment = DB::transaction(function () use (
                $companyId,
                $userId,
                $stockId,
                $quantity,
                $rentalOverride,
                $explicitProductId,
                $remote,
                $linkedToSameProduct
            ) {
                if ($explicitProductId !== null) {
                    return self::importOntoProduct(
                        $companyId,
                        $userId,
                        $explicitProductId,
                        $stockId,
                        $quantity,
                        $rentalOverride,
                        $remote,
                        $linkedToSameProduct
                    );
                }

                return self::createProductAndInventory(
                    $companyId,
                    $userId,
                    $stockId,
                    $quantity,
                    $rentalOverride,
                    $remote
                );
            });
        } catch (HireHopApiException $e) {
            throw $e;
        } catch (QueryException $e) {
            if (self::isDuplicateKey($e)) {
                return [
                    'status' => 409,
                    'body' => ['status' => 'already_in_inventory'],
                ];
            }

            throw $e;
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already linked')) {
                return [
                    'status' => 200,
                    'body' => [
                        'success' => false,
                        'status' => 'already_linked',
                        'message' => $e->getMessage(),
                    ],
                ];
            }

            throw $e;
        }

        return [
            'status' => 201,
            'body' => [
                'success' => true,
                'message' => $explicitProductId !== null
                    ? 'Equipment imported and linked to HireHop'
                    : 'Equipment imported successfully',
                'inventory_id' => $equipment->id,
                'product_id' => $equipment->product_id,
                'hirehop_stock_id' => (string) $equipment->hirehop_stock_id,
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function link(
        int $companyId,
        int $inventoryId,
        string $stockId,
        ?int $quantity,
        ?float $rentalOverride
    ): array {
        $stockId = self::requireStockId($stockId);
        $remote = HireHopService::forCompany($companyId)->findRentalProduct($stockId);
        if ($remote === null) {
            throw new HireHopApiException('HireHop product was not found.', 404, 'HIREHOP_NOT_FOUND');
        }

        try {
            $equipment = DB::transaction(function () use ($companyId, $inventoryId, $stockId, $quantity, $rentalOverride, $remote) {
                $inventory = Equipment::query()
                    ->where('id', $inventoryId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) {
                    return null;
                }

                $other = Equipment::query()
                    ->where('company_id', $companyId)
                    ->where('hirehop_stock_id', $stockId)
                    ->where('id', '!=', $inventoryId)
                    ->lockForUpdate()
                    ->first();

                if ($other) {
                    throw new \RuntimeException('This HireHop product is already linked to another inventory item.');
                }

                $current = trim((string) $inventory->hirehop_stock_id);
                if ($current !== '' && $current !== $stockId) {
                    throw new \RuntimeException('This inventory is already linked to a different HireHop product.');
                }

                $alreadySame = $current === $stockId;
                if ($alreadySame && $quantity === null && $rentalOverride === null) {
                    throw new \RuntimeException('This inventory is already linked to this HireHop product.');
                }

                $inventory->hirehop_stock_id = $stockId;
                if ($quantity !== null) {
                    $inventory->quantity = $quantity;
                }
                if ($rentalOverride !== null) {
                    $inventory->rental_price = $rentalOverride;
                }
                self::fillSoftwareCodeIfEmpty($inventory, $remote);
                $inventory->save();

                return [$inventory->fresh(), $alreadySame];
            });
        } catch (HireHopApiException $e) {
            throw $e;
        } catch (QueryException $e) {
            if (self::isDuplicateKey($e)) {
                return [
                    'status' => 200,
                    'body' => [
                        'success' => false,
                        'status' => 'already_linked',
                        'message' => 'This HireHop product is already linked to another inventory item.',
                    ],
                ];
            }

            throw $e;
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'already linked')) {
                throw $e;
            }

            return [
                'status' => 200,
                'body' => [
                    'success' => false,
                    'status' => 'already_linked',
                    'message' => $e->getMessage(),
                ],
            ];
        }

        if ($equipment === null) {
            return [
                'status' => 400,
                'body' => [
                    'success' => false,
                    'message' => 'Inventory not found or does not belong to this company.',
                ],
            ];
        }

        [$inventory, $alreadySame] = $equipment;

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'message' => $alreadySame ? 'HireHop inventory updated' : 'HireHop linked successfully',
                'inventory_id' => $inventory->id,
                'product_id' => $inventory->product_id,
                'hirehop_stock_id' => (string) $inventory->hirehop_stock_id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $remote
     * @return array<string, mixed>
     */
    private static function hirehopPayload(?array $remote, string $stockId): array
    {
        if ($remote === null) {
            return ['hirehop_stock_id' => $stockId];
        }

        return [
            'hirehop_stock_id' => $remote['hirehop_stock_id'] ?? $stockId,
            'name' => $remote['name'] ?? null,
            'part_number' => $remote['part_number'] ?? null,
            'barcode' => $remote['barcode'] ?? null,
            'status' => $remote['status'] ?? null,
            'type' => $remote['type'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private static function importOntoProduct(
        int $companyId,
        int $userId,
        int $productId,
        string $stockId,
        int $quantity,
        ?float $rentalPrice,
        array $remote,
        bool $alreadyLinkedHere
    ): Equipment {
        $clash = Equipment::query()
            ->where('company_id', $companyId)
            ->where('hirehop_stock_id', $stockId)
            ->lockForUpdate()
            ->first();

        if ($clash && (int) $clash->product_id !== $productId) {
            throw new \RuntimeException('This HireHop product is already linked to a different product.');
        }

        if ($clash) {
            $clash->quantity = $quantity;
            if ($rentalPrice !== null) {
                $clash->rental_price = $rentalPrice;
            }
            self::fillSoftwareCodeIfEmpty($clash, $remote);
            $clash->save();

            return $clash->fresh();
        }

        if ($alreadyLinkedHere) {
            throw new \RuntimeException('This HireHop product is already linked to a different product.');
        }

        $inventory = Equipment::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($inventory) {
            $current = trim((string) $inventory->hirehop_stock_id);
            if ($current !== '' && $current !== $stockId) {
                throw new \RuntimeException('This inventory is already linked to a different HireHop product.');
            }

            $inventory->hirehop_stock_id = $stockId;
            $inventory->quantity = $quantity;
            if ($rentalPrice !== null) {
                $inventory->rental_price = $rentalPrice;
            }
            self::fillSoftwareCodeIfEmpty($inventory, $remote);
            $inventory->save();

            return $inventory->fresh();
        }

        $product = Product::query()->findOrFail($productId);

        return self::createInventory($companyId, $userId, $product, $stockId, $quantity, $rentalPrice, $remote);
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private static function createProductAndInventory(
        int $companyId,
        int $userId,
        string $stockId,
        int $quantity,
        ?float $rentalPrice,
        array $remote
    ): Equipment {
        $clash = Equipment::query()
            ->where('company_id', $companyId)
            ->where('hirehop_stock_id', $stockId)
            ->lockForUpdate()
            ->first();

        if ($clash) {
            throw new \RuntimeException('This HireHop product is already linked to another inventory item.');
        }

        $name = trim((string) ($remote['name'] ?? ''));
        $parsed = FlexService::parseBrandAndModel($name);
        $model = trim((string) ($parsed['model'] ?? ''));
        if ($model === '') {
            $model = $name !== '' ? $name : 'Unknown';
        }

        $product = Product::query()->create([
            'category_id' => null,
            'sub_category_id' => null,
            'brand_id' => $parsed['brand_id'] ?? null,
            'model' => mb_substr($model, 0, 255),
            'psm_code' => PsmCodeGenerator::next(),
            'is_verified' => 0,
            'source' => 'hirehop',
        ]);

        return self::createInventory($companyId, $userId, $product, $stockId, $quantity, $rentalPrice, $remote);
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private static function createInventory(
        int $companyId,
        int $userId,
        Product $product,
        string $stockId,
        int $quantity,
        ?float $rentalPrice,
        array $remote
    ): Equipment {
        $equipment = Equipment::query()->create(array_merge([
            'user_id' => $userId,
            'company_id' => $companyId,
            'product_id' => $product->id,
            'hirehop_stock_id' => $stockId,
            'software_code' => self::softwareCode($remote, $stockId),
            'quantity' => $quantity,
            'rental_price' => $rentalPrice ?? 0,
        ], CompanyInventorySpecs::attributesFromProduct($product)));

        return $equipment->fresh();
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private static function fillSoftwareCodeIfEmpty(Equipment $inventory, array $remote): void
    {
        if (trim((string) $inventory->software_code) !== '') {
            return;
        }

        $code = self::softwareCode($remote, (string) $inventory->hirehop_stock_id);
        if ($code !== '') {
            $inventory->software_code = $code;
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private static function softwareCode(array $remote, string $stockId): string
    {
        $part = trim((string) ($remote['part_number'] ?? ''));
        if ($part !== '') {
            return $part;
        }

        $barcode = trim((string) ($remote['barcode'] ?? ''));
        if ($barcode !== '') {
            return $barcode;
        }

        return $stockId;
    }

    private static function requireStockId(string $stockId): string
    {
        $canonical = HireHopService::canonicalStockId(trim($stockId));
        if ($canonical === null || $canonical === '0') {
            throw new HireHopApiException('hirehop_stock_id is required.', 422, 'VALIDATION_FAILED');
        }

        return $canonical;
    }

    private static function isDuplicateKey(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            || str_contains($exception->getMessage(), 'Duplicate entry');
    }
}
