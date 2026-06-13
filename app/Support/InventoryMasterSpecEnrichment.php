<?php

namespace App\Support;

use App\Models\InventoryMasterAiSpec;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

final class InventoryMasterSpecEnrichment
{
    /** @var list<string> */
    public const SPEC_FIELDS = [
        'height',
        'width',
        'length',
        'weight',
        'linear_unit_id',
        'weight_unit_id',
    ];

    public static function isFieldEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    public static function hasCompletePhysicalSpecs(Product $product): bool
    {
        foreach (self::SPEC_FIELDS as $field) {
            if (self::isFieldEmpty($product->{$field})) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function missingSpecFields(Product $product): array
    {
        $missing = [];

        foreach (self::SPEC_FIELDS as $field) {
            if (self::isFieldEmpty($product->{$field})) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    public static function hasSufficientLookupInformation(Product $product): bool
    {
        return trim((string) $product->model) !== '';
    }

    public static function hasPendingEnrichment(int $inventoryMasterId): bool
    {
        return InventoryMasterAiSpec::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->where('status', InventoryMasterAiSpec::STATUS_PENDING)
            ->exists();
    }

    public static function scopeMissingPhysicalSpecs(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            foreach (self::SPEC_FIELDS as $field) {
                $inner->orWhereNull($field);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildLookupContext(Product $product): array
    {
        $product->loadMissing(['brand:id,name', 'category:id,name', 'subCategory:id,name']);

        return array_filter([
            'product_name' => $product->model,
            'manufacturer' => $product->brand?->name,
            'category' => $product->category?->name,
            'sub_category' => $product->subCategory?->name,
            'psm_code' => $product->psm_code,
            'webpage_url' => $product->webpage_url,
            'country_of_origin' => $product->country_of_origin,
            'hsn_code' => $product->hsn_code,
            'source' => $product->source,
            'missing_fields' => self::missingSpecFields($product),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
