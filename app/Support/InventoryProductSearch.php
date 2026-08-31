<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Multi-keyword AND search across inventory_master fields and related brand/taxonomy.
 *
 * Each whitespace-separated keyword must match at least one of: id substring (numeric), model, psm_code,
 * brand name, category, subcategory, normalized_full_name, and (for joined queries) concatenated brand+model.
 *
 * A purely numeric search term matches only product IDs containing that digit sequence (e.g. "485" matches
 * 485, 4851, 1485) and results are ordered by id ascending.
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

    public static function isNumericId(string $word): bool
    {
        return $word !== '' && ctype_digit($word);
    }

    public static function isNumericIdSearch(string $searchValue): bool
    {
        return self::isNumericId(trim($searchValue));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    private static function applyIdSubstringCondition($query, string $word, string $idColumn = 'inventory_master.id'): void
    {
        if (!self::isNumericId($word)) {
            return;
        }

        $idLike = '%' . self::escapeLike($word) . '%';
        $query->whereRaw("CAST({$idColumn} AS CHAR) LIKE ?", [$idLike]);
    }

    private static function productDescriptionSql(): string
    {
        return 'LOWER(TRIM(CONCAT(COALESCE((SELECT name FROM brands WHERE brands.id = inventory_master.brand_id LIMIT 1), \'\'), \' \', COALESCE(inventory_master.model, \'\'))))';
    }

    /**
     * HireTrack-style AND search on product description (brand + model) and PSM code.
     * Each whitespace-separated word must appear in the description or psm_code:
     * ((description LIKE '%word1%') OR (psm_code LIKE '%word1%')) AND ...
     */
    public static function applyDescriptionAndSearchToProductQuery(Builder $query, string $searchValue): void
    {
        $searchValue = trim($searchValue);
        if ($searchValue === '') {
            return;
        }

        $keywords = self::splitKeywords($searchValue);
        if ($keywords === []) {
            return;
        }

        $descriptionSql = self::productDescriptionSql();

        foreach ($keywords as $word) {
            $like = '%' . self::escapeLike(mb_strtolower($word, 'UTF-8')) . '%';

            $query->where(function ($q) use ($descriptionSql, $like) {
                $q->whereRaw("{$descriptionSql} LIKE ?", [$like])
                    ->orWhereRaw('LOWER(COALESCE(inventory_master.psm_code, \'\')) LIKE ?', [$like]);
            });
        }
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

        if (self::isNumericIdSearch($searchValue)) {
            self::applyIdSubstringCondition($query, $searchValue);

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

                if (self::isNumericId($word)) {
                    $idLike = '%' . self::escapeLike($word) . '%';
                    $q->orWhereRaw('CAST(inventory_master.id AS CHAR) LIKE ?', [$idLike]);
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

        if (self::isNumericIdSearch($searchValue)) {
            self::applyIdSubstringCondition($query, $searchValue);

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

                if (self::isNumericId($word)) {
                    $idLike = '%' . self::escapeLike($word) . '%';
                    $q->orWhereRaw('CAST(inventory_master.id AS CHAR) LIKE ?', [$idLike]);
                }
            });
        }
    }

    /**
     * Relevance ordering for description + PSM code search (exact matches first).
     */
    public static function applyDescriptionSearchRelevanceOrderToProductQuery(Builder $query, string $searchValue): void
    {
        $searchValue = trim($searchValue);
        if ($searchValue === '') {
            return;
        }

        [$caseSql, $bindings] = self::buildDescriptionSearchRelevanceCaseSql($searchValue);

        $query->orderByRaw($caseSql, $bindings)
            ->orderByRaw('CHAR_LENGTH(TRIM(COALESCE(inventory_master.model, \'\'))) ASC')
            ->orderBy('inventory_master.id', 'asc');
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

        if (self::isNumericIdSearch($searchValue)) {
            $query->orderBy('inventory_master.id', 'asc');

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

        if (self::isNumericIdSearch($searchValue)) {
            $query->orderBy('inventory_master.id', 'asc');

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
    private static function buildDescriptionSearchRelevanceCaseSql(string $searchValue): array
    {
        $lower = mb_strtolower($searchValue, 'UTF-8');
        $phraseLike = '%' . self::escapeLike($lower) . '%';
        $descriptionSql = self::productDescriptionSql();
        $normalizedPhrase = ProductNormalizer::normalizeFullName(null, $searchValue);

        $whens = [];
        $bindings = [];

        $whens[] = 'WHEN inventory_master.psm_code IS NOT NULL AND LOWER(TRIM(inventory_master.psm_code)) = ? THEN 1';
        $bindings[] = $lower;

        $whens[] = "WHEN {$descriptionSql} = ? THEN 2";
        $bindings[] = $lower;

        $whens[] = "WHEN {$descriptionSql} LIKE ? THEN 3";
        $bindings[] = $phraseLike;

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) LIKE ? THEN 4';
        $bindings[] = $phraseLike;

        $whens[] = "WHEN {$descriptionSql} LIKE ? THEN 5";
        $bindings[] = self::escapeLike($lower) . '%';

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) = ? THEN 6';
        $bindings[] = $lower;

        $whens[] = 'WHEN inventory_master.psm_code IS NOT NULL AND LOWER(TRIM(inventory_master.psm_code)) LIKE ? THEN 7';
        $bindings[] = self::escapeLike($lower) . '%';

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name = ? THEN 8';
            $bindings[] = $normalizedPhrase;

            $whens[] = 'WHEN inventory_master.normalized_full_name LIKE ? THEN 9';
            $bindings[] = '%' . self::escapeLike($normalizedPhrase) . '%';
        }

        $whens[] = 'ELSE 100';

        return ['CASE ' . implode(' ', $whens) . ' END', $bindings];
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

        if (self::isNumericId($searchValue)) {
            $whens[] = 'WHEN inventory_master.id = ? THEN -2';
            $bindings[] = (int) $searchValue;

            $whens[] = 'WHEN CAST(inventory_master.id AS CHAR) LIKE ? THEN -1';
            $bindings[] = self::escapeLike($searchValue) . '%';

            $whens[] = 'WHEN CAST(inventory_master.id AS CHAR) LIKE ? THEN 0';
            $bindings[] = '%' . self::escapeLike($searchValue) . '%';
        }

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) = ? THEN 1';
        $bindings[] = $lower;

        $whens[] = 'WHEN LOWER(TRIM(CONCAT(COALESCE((SELECT name FROM brands WHERE brands.id = inventory_master.brand_id LIMIT 1), \'\'), \' \', COALESCE(inventory_master.model, \'\')))) = ? THEN 2';
        $bindings[] = $lower;

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name = ? THEN 3';
            $bindings[] = $normalizedPhrase;
        }

        if ($normalizedCode && ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            $whens[] = 'WHEN inventory_master.normalized_model = ? THEN 4';
            $bindings[] = $normalizedCode;
        }

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) LIKE ? THEN 5';
        $bindings[] = $lower . '%';

        $whens[] = 'WHEN inventory_master.psm_code IS NOT NULL AND LOWER(TRIM(inventory_master.psm_code)) = ? THEN 6';
        $bindings[] = $lower;

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name LIKE ? THEN 7';
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

        if (self::isNumericId($searchValue)) {
            $whens[] = 'WHEN inventory_master.id = ? THEN -2';
            $bindings[] = (int) $searchValue;

            $whens[] = 'WHEN CAST(inventory_master.id AS CHAR) LIKE ? THEN -1';
            $bindings[] = self::escapeLike($searchValue) . '%';

            $whens[] = 'WHEN CAST(inventory_master.id AS CHAR) LIKE ? THEN 0';
            $bindings[] = '%' . self::escapeLike($searchValue) . '%';
        }

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) = ? THEN 1';
        $bindings[] = $lower;

        $whens[] = 'WHEN LOWER(TRIM(CONCAT(COALESCE(brands.name, \'\'), \' \', COALESCE(inventory_master.model, \'\')))) = ? THEN 2';
        $bindings[] = $lower;

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name = ? THEN 3';
            $bindings[] = $normalizedPhrase;
        }

        if ($normalizedCode && ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            $whens[] = 'WHEN inventory_master.normalized_model = ? THEN 4';
            $bindings[] = $normalizedCode;
        }

        $whens[] = 'WHEN LOWER(TRIM(inventory_master.model)) LIKE ? THEN 5';
        $bindings[] = $lower . '%';

        $whens[] = 'WHEN inventory_master.psm_code IS NOT NULL AND LOWER(TRIM(inventory_master.psm_code)) = ? THEN 6';
        $bindings[] = $lower;

        if ($normalizedPhrase !== null && $normalizedPhrase !== '') {
            $whens[] = 'WHEN inventory_master.normalized_full_name LIKE ? THEN 7';
            $bindings[] = '%' . self::escapeLike($normalizedPhrase) . '%';
        }

        $whens[] = 'ELSE 100';

        return ['CASE ' . implode(' ', $whens) . ' END', $bindings];
    }
}
