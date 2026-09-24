<?php

namespace App\Services\InventoryMaster;

use App\Models\Equipment;
use App\Models\InventoryMasterImage;
use App\Models\Product;
use App\Support\CompanyInventorySpecs;
use App\Support\InventoryImageManagementService;
use App\Support\InventoryProductSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PsmEquipmentService
{
    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateCatalog(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = Product::query()
            ->with([
                'brand:id,name',
                'primaryMasterImage',
            ]);

        $searchValue = trim((string) $search);
        if ($searchValue !== '') {
            InventoryProductSearch::applyToProductQuery($query, $searchValue);
            InventoryProductSearch::applyRelevanceOrderToProductQuery($query, $searchValue);
        } else {
            $query->orderByDesc('inventory_master.id');
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public function importedProductIdsForCompany(int $companyId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Equipment::query()
            ->where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatListingItem(Product $product, bool $alreadyInCompanyInventory = false): array
    {
        return [
            'id' => $product->id,
            'name' => $this->displayName($product),
            'model' => $product->model,
            'psm_code' => $product->psm_code,
            'primary_image' => $this->formatImage($product->primaryMasterImage),
            'already_in_company_inventory' => $alreadyInCompanyInventory,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatDetails(Product $product, bool $alreadyInCompanyInventory = false): array
    {
        $product->loadMissing([
            'brand:id,name',
            'category:id,name',
            'subCategory:id,name,category_id',
            'linearUnit:id,code,name',
            'weightUnit:id,code,name',
            'masterImages',
        ]);

        $specs = CompanyInventorySpecs::productSpecsForJson($product);
        $images = $product->masterImages
            ->map(fn (InventoryMasterImage $image) => $this->formatImage($image))
            ->filter()
            ->values();

        $primary = $images->firstWhere('is_primary', true) ?? $images->first();

        return [
            'id' => $product->id,
            'name' => $this->displayName($product),
            'model' => $product->model,
            'psm_code' => $product->psm_code,
            'webpage_url' => $product->webpage_url,
            'is_verified' => $product->is_verified,
            'source' => $product->source,
            'replacement_price' => $product->replacement_price !== null
                ? (float) $product->replacement_price
                : null,
            'height' => $specs['height'],
            'width' => $specs['width'],
            'length' => $specs['length'],
            'weight' => $specs['weight'],
            'linear_unit_id' => $specs['linear_unit_id'],
            'weight_unit_id' => $specs['weight_unit_id'],
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
            'country_of_origin' => $specs['country_of_origin'],
            'iso_code_2' => $product->iso_code_2,
            'iso_code_3' => $product->iso_code_3,
            'hsn_code' => $specs['hsn_code'],
            'dimensions_display' => $specs['dimensions_display'],
            'weight_display' => $specs['weight_display'],
            'brand' => $product->brand ? [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
            ] : null,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'sub_category' => $product->subCategory ? [
                'id' => $product->subCategory->id,
                'name' => $product->subCategory->name,
                'category_id' => $product->subCategory->category_id,
            ] : null,
            'primary_image' => $primary,
            'images' => $images->all(),
            'already_in_company_inventory' => $alreadyInCompanyInventory,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formatImage(?InventoryMasterImage $image): ?array
    {
        if (! $image) {
            return null;
        }

        return [
            'id' => $image->id,
            'url' => InventoryImageManagementService::publicUrl((string) $image->image_path),
            'is_primary' => (bool) $image->is_primary,
            'sort_order' => $image->sort_order,
        ];
    }

    private function displayName(Product $product): string
    {
        $brandName = $product->brand?->name;
        $model = trim((string) ($product->model ?? ''));

        return trim(($brandName ? $brandName.' ' : '').$model);
    }
}
