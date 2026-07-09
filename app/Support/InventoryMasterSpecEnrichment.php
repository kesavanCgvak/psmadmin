<?php

namespace App\Support;

use App\Models\InventoryMasterAiSpec;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

    /** @var list<string> */
    public const DIMENSION_WEIGHT_FIELDS = [
        'height',
        'width',
        'length',
        'weight',
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

    public static function hasApprovedEnrichment(int $inventoryMasterId): bool
    {
        return InventoryMasterAiSpec::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->where('status', InventoryMasterAiSpec::STATUS_APPROVED)
            ->exists();
    }

    /**
     * Approved AI enrichment exists but inventory_master still has empty spec fields.
     */
    public static function hasApprovedPartialEnrichment(Product $product): bool
    {
        if (self::hasCompletePhysicalSpecs($product)) {
            return false;
        }

        return self::hasApprovedEnrichment($product->id);
    }

    /**
     * @param  list<string>  $missingProductFields
     * @param  list<string>  $fillsMissing
     */
    public static function allMissingFieldsCovered(array $missingProductFields, array $fillsMissing): bool
    {
        foreach ($missingProductFields as $field) {
            if (!in_array($field, $fillsMissing, true)) {
                return false;
            }
        }

        return $missingProductFields !== [];
    }

    /**
     * Re-open the latest approved spec when inventory_master is still incomplete.
     */
    public static function reopenLatestApprovedSpecForReview(int $inventoryMasterId): ?InventoryMasterAiSpec
    {
        if (self::hasPendingEnrichment($inventoryMasterId)) {
            return null;
        }

        $spec = InventoryMasterAiSpec::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->where('status', InventoryMasterAiSpec::STATUS_APPROVED)
            ->orderByDesc('id')
            ->first();

        if (!$spec) {
            return null;
        }

        $spec->update(['status' => InventoryMasterAiSpec::STATUS_PENDING]);

        return $spec->fresh();
    }

    /**
     * @return list<int>
     */
    public static function inventoryMasterIdsWithApprovedPartialEnrichment(): array
    {
        return Product::query()
            ->select('inventory_master.id')
            ->tap(fn (Builder $builder) => self::scopeMissingPhysicalSpecs($builder))
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_specs')
                    ->whereColumn('inventory_master_ai_specs.inventory_master_id', 'inventory_master.id')
                    ->where('inventory_master_ai_specs.status', InventoryMasterAiSpec::STATUS_APPROVED);
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_specs')
                    ->whereColumn('inventory_master_ai_specs.inventory_master_id', 'inventory_master.id')
                    ->where('inventory_master_ai_specs.status', InventoryMasterAiSpec::STATUS_PENDING);
            })
            ->orderBy('inventory_master.id')
            ->pluck('id')
            ->all();
    }

    public static function scopeEligibleForEnrichment(Builder $query, bool $retryIncomplete = false): Builder
    {
        $query->tap(fn (Builder $builder) => self::scopeMissingPhysicalSpecs($builder))
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_specs')
                    ->whereColumn('inventory_master_ai_specs.inventory_master_id', 'inventory_master.id')
                    ->where('inventory_master_ai_specs.status', InventoryMasterAiSpec::STATUS_PENDING);
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_rejections')
                    ->whereColumn('inventory_master_ai_rejections.inventory_master_id', 'inventory_master.id');
            });

        if (!$retryIncomplete) {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_specs')
                    ->whereColumn('inventory_master_ai_specs.inventory_master_id', 'inventory_master.id')
                    ->where('inventory_master_ai_specs.status', InventoryMasterAiSpec::STATUS_APPROVED);
            })->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('inventory_master_ai_specs')
                    ->whereColumn('inventory_master_ai_specs.inventory_master_id', 'inventory_master.id')
                    ->where('inventory_master_ai_specs.status', InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION);
            });
        }

        return $query;
    }

    public static function scopeMissingPhysicalSpecs(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            foreach (self::SPEC_FIELDS as $field) {
                $inner->orWhereNull($field);
            }
        });
    }

    public static function scopeMissingDimensionOrWeight(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            foreach (self::DIMENSION_WEIGHT_FIELDS as $field) {
                $inner->orWhereNull($field)->orWhere($field, '');
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function missingDimensionOrWeightFields(Product $product): array
    {
        $missing = [];

        foreach (self::DIMENSION_WEIGHT_FIELDS as $field) {
            if (self::isFieldEmpty($product->{$field})) {
                $missing[] = $field;
            }
        }

        return $missing;
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
