<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Multi-keyword AND search across inventory_master fields and related brand/taxonomy.
 *
 * Each whitespace-separated keyword must match at least one of: model, psm_code, brand name,
 * category, subcategory, normalized_full_name, and (for joined queries) concatenated brand+model.
 */
class InventoryProductSearch
{
    public static function splitKeywords(string $search): array
    {
        $parts = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($parts, static fn ($p) => $p !== ''));
    }

    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Apply AND-of-keywords search to an Eloquent query on {@see \App\Models\Product} (inventory_master).
     */
    public static function applyToProductQuery(Builder $query, string $searchValue, bool $includeTaxonomy = true): void
    {
        $searchValue = trim($searchValue);
        if ($searchValue === '') {
            return;
        }

        $keywords = self::splitKeywords($searchValue);
        if ($keywords === []) {
            return;
        }

        foreach ($keywords as $word) {
            $query->where(function ($q) use ($word, $includeTaxonomy) {
                $like = '%' . self::escapeLike($word) . '%';

                $q->where('inventory_master.model', 'like', $like)
                    ->orWhere('inventory_master.psm_code', 'like', $like)
                    ->orWhereHas('brand', function ($brandQuery) use ($like) {
                        $brandQuery->where('name', 'like', $like);
                    });

                if ($includeTaxonomy) {
                    $q->orWhereHas('category', function ($categoryQuery) use ($like) {
                        $categoryQuery->where('name', 'like', $like);
                    })->orWhereHas('subCategory', function ($subCategoryQuery) use ($like) {
                        $subCategoryQuery->where('name', 'like', $like);
                    });
                }

                $norm = ProductNormalizer::normalizeCode($word);
                if ($norm !== null && $norm !== '') {
                    $nLike = '%' . self::escapeLike($norm) . '%';
                    $q->orWhere('inventory_master.normalized_full_name', 'like', $nLike);
                }
            });
        }
    }

    /**
     * Same rules for company_inventory + inventory_master + brands joined queries (DataTables).
     */
    public static function applyToCompanyInventoryJoinedQuery(Builder $query, string $searchValue): void
    {
        $searchValue = trim($searchValue);
        if ($searchValue === '') {
            return;
        }

        $keywords = self::splitKeywords($searchValue);
        if ($keywords === []) {
            return;
        }

        foreach ($keywords as $word) {
            $like = '%' . self::escapeLike($word) . '%';

            $query->where(function ($q) use ($like, $word) {
                $q->where('inventory_master.model', 'like', $like)
                    ->orWhere('inventory_master.psm_code', 'like', $like)
                    ->orWhere('brands.name', 'like', $like)
                    ->orWhere('company_inventory.software_code', 'like', $like);

                $norm = ProductNormalizer::normalizeCode($word);
                if ($norm !== null && $norm !== '') {
                    $nLike = '%' . self::escapeLike($norm) . '%';
                    $q->orWhere('inventory_master.normalized_full_name', 'like', $nLike);
                }

                $q->orWhereRaw(
                    'LOWER(TRIM(CONCAT(COALESCE(brands.name, \'\'), \' \', COALESCE(inventory_master.model, \'\')))) LIKE ?',
                    [strtolower($like)]
                );
            });
        }
    }

    /**
     * Order inventory_master rows by match quality when a search is active (best matches first).
     */
    public static function applyRelevanceOrderToProductQuery(Builder $query, string $searchValue): void
    {
        $searchValue = trim($searchValue);
        if ($searchValue === '') {
            return;
        }

        [$caseSql, $bindings] = self::buildProductRelevanceCaseSql($searchValue);

        $query->orderByRaw($caseSql, $bindings)
            ->orderByRaw('CHAR_LENGTH(TRIM(COALESCE(inventory_master.model, \'\'))) ASC')
            ->orderBy('inventory_master.id', 'asc');
    }

    /**
     * Same relevance ordering for company_inventory + inventory_master + brands (join already present).
     */
    public static function applyRelevanceOrderToCompanyInventoryJoinedQuery(Builder $query, string $searchValue): void
    {
        $searchValue = trim($searchValue);
        if ($searchValue === '') {
            return;
        }

        [$caseSql, $bindings] = self::buildJoinedProductRelevanceCaseSql($searchValue);

        $query->orderByRaw($caseSql, $bindings)
            ->orderByRaw('CHAR_LENGTH(TRIM(COALESCE(inventory_master.model, \'\'))) ASC')
            ->orderBy('company_inventory.id', 'asc');
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function buildProductRelevanceCaseSql(string $searchValue): array
    {
        $lower = mb_strtolower($searchValue, 'UTF-8');
        $normalizedPhrase = ProductNormalizer::normalizeFullName(null, $searchValue);
        $normalizedCode = ProductNormalizer::normalizeCode($searchValue);

        $whens = [];
        $bindings = [];

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) = ? THEN 0';
        $bindings[] = $lower;

        $whens[] = 'WHEN LOWER(TRIM(CONCAT(COALESCE((SELECT name FROM brands WHERE brands.id = inventory_master.brand_id LIMIT 1), \'\'), \' \', COALESCE(inventory_master.model, \'\')))) = ? THEN 1';
        $bindings[] = $lower;

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name = ? THEN 2';
            $bindings[] = $normalizedPhrase;
        }

        if ($normalizedCode && ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            $whens[] = 'WHEN inventory_master.normalized_model = ? THEN 3';
            $bindings[] = $normalizedCode;
        }

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) LIKE ? THEN 4';
        $bindings[] = $lower . '%';

        $whens[] = 'WHEN inventory_master.psm_code IS NOT NULL AND LOWER(TRIM(inventory_master.psm_code)) = ? THEN 5';
        $bindings[] = $lower;

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name LIKE ? THEN 6';
            $bindings[] = '%' . self::escapeLike($normalizedPhrase) . '%';
        }

        $whens[] = 'ELSE 100';

        return ['CASE ' . implode(' ', $whens) . ' END', $bindings];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function buildJoinedProductRelevanceCaseSql(string $searchValue): array
    {
        $lower = mb_strtolower($searchValue, 'UTF-8');
        $normalizedPhrase = ProductNormalizer::normalizeFullName(null, $searchValue);
        $normalizedCode = ProductNormalizer::normalizeCode($searchValue);

        $whens = [];
        $bindings = [];

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) = ? THEN 0';
        $bindings[] = $lower;

        $whens[] = 'WHEN LOWER(TRIM(CONCAT(COALESCE(brands.name, \'\'), \' \', COALESCE(inventory_master.model, \'\')))) = ? THEN 1';
        $bindings[] = $lower;

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name = ? THEN 2';
            $bindings[] = $normalizedPhrase;
        }

        if ($normalizedCode && ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            $whens[] = 'WHEN inventory_master.normalized_model = ? THEN 3';
            $bindings[] = $normalizedCode;
        }

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) LIKE ? THEN 4';
        $bindings[] = $lower . '%';

        $whens[] = 'WHEN inventory_master.psm_code IS NOT NULL AND LOWER(TRIM(inventory_master.psm_code)) = ? THEN 5';
        $bindings[] = $lower;

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name LIKE ? THEN 6';
            $bindings[] = '%' . self::escapeLike($normalizedPhrase) . '%';
        }

        $whens[] = 'ELSE 100';

        return ['CASE ' . implode(' ', $whens) . ' END', $bindings];
    }
}
