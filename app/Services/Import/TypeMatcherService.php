<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SubCategory;

class TypeMatcherService
{
    /**
     * Infer product type (category, brand, sub_category) from description or matched products
     * This prevents creating new types when matching products
     * 
     * @param string $description Original product description
     * @param array $matchedProducts Array of matched products (from ProductMatcherService)
     * @return array ['category_id' => int|null, 'brand_id' => int|null, 'sub_category_id' => int|null]
     */
    public function inferTypes(string $description, array $matchedProducts = []): array
    {
        $result = [
            'category_id' => null,
            'brand_id' => null,
            'sub_category_id' => null,
        ];

        // Strategy 1: If we have matched products, use the most common type from matches
        if (!empty($matchedProducts)) {
            $result = $this->extractTypesFromMatches($matchedProducts);
        }

        // Strategy 2: Try to extract brand from description
        if (!$result['brand_id']) {
            $result['brand_id'] = $this->extractBrandFromDescription($description);
        }

        // Strategy 3: If we have a brand, try to find common category for that brand
        if ($result['brand_id'] && !$result['category_id']) {
            $result['category_id'] = $this->findCommonCategoryForBrand($result['brand_id']);
        }

        return $result;
    }

    /**
     * Extract types from matched products
     * Uses the most common category/brand/sub_category from matches
     */
    protected function extractTypesFromMatches(array $matchedProducts): array
    {
        $categoryCounts = [];
        $brandCounts = [];
        $subCategoryCounts = [];

        foreach ($matchedProducts as $match) {
            $productId = $match['product_id'] ?? null;
            if (!$productId) {
                continue;
            }

            $product = Product::with(['category', 'brand', 'subCategory'])->find($productId);
            if (!$product) {
                continue;
            }

            if ($product->category_id) {
                $categoryCounts[$product->category_id] = ($categoryCounts[$product->category_id] ?? 0) + 1;
            }

            if ($product->brand_id) {
                $brandCounts[$product->brand_id] = ($brandCounts[$product->brand_id] ?? 0) + 1;
            }

            if ($product->sub_category_id) {
                $subCategoryCounts[$product->sub_category_id] = ($subCategoryCounts[$product->sub_category_id] ?? 0) + 1;
            }
        }

        // Get the most common type (highest count)
        $getMostCommon = function ($counts) {
            if (empty($counts)) {
                return null;
            }
            arsort($counts);
            return array_key_first($counts);
        };

        return [
            'category_id' => $getMostCommon($categoryCounts),
            'brand_id' => $getMostCommon($brandCounts),
            'sub_category_id' => $getMostCommon($subCategoryCounts),
        ];
    }

    /**
     * Extract brand from product description
     * Tries to match known brand names in the description
     */
    protected function extractBrandFromDescription(string $description): ?int
    {
        $normalized = strtolower($description);
        
        // Get all brands and check if any brand name appears in description
        $brands = Brand::all();
        
        foreach ($brands as $brand) {
            $brandName = strtolower($brand->name);
            
            // Check if brand name appears in description
            if (str_contains($normalized, $brandName)) {
                return $brand->id;
            }
            
            // Check common abbreviations
            $abbreviations = $this->getBrandAbbreviations($brand->name);
            foreach ($abbreviations as $abbr) {
                // Skip empty abbreviations to prevent regex errors
                if (empty(trim($abbr))) {
                    continue;
                }
                // Properly escape the abbreviation for use in regex
                $escapedAbbr = preg_quote(trim($abbr), '/');
                if (!empty($escapedAbbr) && preg_match('/\b' . $escapedAbbr . '\b/i', $normalized)) {
                    return $brand->id;
                }
            }
        }
        
        return null;
    }

    /**
     * Get common abbreviations for a brand name
     */
    protected function getBrandAbbreviations(string $brandName): array
    {
        $abbreviations = [
            'klark-teknik' => ['kt', 'klark teknik', 'klark'],
            'klark teknik' => ['kt', 'klark-teknik', 'klark'],
            // Add more brand abbreviations as needed
        ];
        
        $normalized = strtolower($brandName);
        return $abbreviations[$normalized] ?? [];
    }

    /**
     * Find the most common category for products of a given brand
     */
    protected function findCommonCategoryForBrand(int $brandId): ?int
    {
        $categoryCounts = Product::where('brand_id', $brandId)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) as count')
            ->groupBy('category_id')
            ->orderByDesc('count')
            ->limit(1)
            ->value('category_id');
        
        return $categoryCounts;
    }
}

