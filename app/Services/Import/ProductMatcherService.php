<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ImportSessionItem;
use App\Models\Category;
use App\Support\ProductNormalizer;
use Illuminate\Support\Collection;

class ProductMatcherService
{
    /**
     * Multi-layer matching strategy to find products with high accuracy
     * Priority: PSM Code > Exact Model > Normalized Similarity > Fuzzy Match
     * 
     * @param ImportSessionItem $item
     * @param float $minConfidence Minimum confidence threshold (0.0 to 1.0)
     * @return Collection Collection of matches with product_id, psm_code, confidence, match_type
     */
    public function findMatches(ImportSessionItem $item, float $minConfidence = 0.70): Collection
    {
        $matches = collect();
        
        // Extract/infer expected category from description
        $expectedCategoryId = $this->extractCategoryFromDescription($item->original_description);
        
        // Strategy 0: EXACT MATCH FIRST (case-insensitive, trimmed)
        // This catches products like "CHAUVET ROGUE R2 BEAM FIXTURE" matching exactly
        $exactMatches = $this->findExactDescriptionMatches($item, $expectedCategoryId);
        $matches = $matches->merge($exactMatches);
        
        // If we found exact matches with category alignment, return them
        // But only if they have high confidence after category penalty
        if ($exactMatches->where('confidence', '>=', 0.90)->isNotEmpty()) {
            return $matches
                ->unique('product_id')
                ->where('confidence', '>=', $minConfidence)
                ->sortByDesc('confidence')
                ->take(10)
                ->values();
        }
        
        // Strategy 1: Extract model number and find exact matches
        $modelNumber = $this->extractModelNumber($item->original_description);
        
        if ($modelNumber) {
            // Exact model matches with brand awareness
            $exactModelMatches = $this->findExactModelMatches($modelNumber, $item, $expectedCategoryId);
            $matches = $matches->merge($exactModelMatches);
            
            // PSM code lookup - if model matches a product with PSM code, find all products with that PSM code
            // This is critical: PSM code is the universal identifier for the same product
            // But apply category penalties if categories don't align
            $psmMatches = $this->findByPsmCodeViaModel($modelNumber, $expectedCategoryId);
            $matches = $matches->merge($psmMatches);
        }
        
        // Strategy 2: Normalized similarity matching (handles brand variations)
        // This works for both products with and without model numbers
        $normalizedMatches = $this->findNormalizedSimilarityMatches($item, $expectedCategoryId);
        $matches = $matches->merge($normalizedMatches);
        
        // Strategy 3: Enhanced description matching for products without model numbers
        // If no model number found, do a broader search
        if (!$modelNumber) {
            $descriptionMatches = $this->findByDescription($item, $expectedCategoryId);
            $matches = $matches->merge($descriptionMatches);
        }
        
        // Strategy 4: Fuzzy match as fallback (only if no high-confidence matches found)
        if ($matches->where('confidence', '>=', 0.85)->isEmpty()) {
            $fuzzyMatches = $this->findFuzzyMatches($item, $expectedCategoryId);
            $matches = $matches->merge($fuzzyMatches);
        }
        
        // Deduplicate by product_id, filter by confidence, sort by confidence desc, limit to top 10
        return $matches
            ->unique('product_id')
            ->where('confidence', '>=', $minConfidence)
            ->sortByDesc('confidence')
            ->take(10)
            ->values();
    }
    
    /**
     * Find exact description matches using normalized codes and full names
     * Handles variations like "DML-1122", "DML1122", "EV-DML1122", "Apogee SSM -"
     */
    protected function findExactDescriptionMatches(ImportSessionItem $item, ?int $expectedCategoryId = null): Collection
    {
        $description = trim($item->original_description);
        
        // Extract and normalize model code
        $modelCode = ProductNormalizer::extractModelCode($description);
        $normalizedCode = $modelCode ? ProductNormalizer::normalizeCode($modelCode) : null;
        
        // Normalize full description (removes metadata, special chars)
        $normalizedFull = ProductNormalizer::normalizeFullName(null, $description);
        
        // Build query using normalized columns
        $query = Product::leftJoin('brands', 'products.brand_id', '=', 'brands.id')
            ->where(function ($q) use ($normalizedCode, $normalizedFull) {
                // Match by normalized model code (handles DML-1122, DML1122, etc.)
                if ($normalizedCode && ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
                    $q->where('products.normalized_model', $normalizedCode)
                      ->orWhere('products.normalized_model', 'LIKE', '%' . $normalizedCode . '%');
                }
                
                // Match by normalized full name (handles "Apogee SSM -", "EV-DML1122", etc.)
                if ($normalizedFull) {
                    $q->orWhere('products.normalized_full_name', $normalizedFull)
                      ->orWhere('products.normalized_full_name', 'LIKE', '%' . $normalizedFull . '%');
                }
            })
            ->select('products.*')
            ->with(['brand', 'category']);
        
        // Order by exact match first, then contains, prioritize category match if expected
        if ($normalizedCode) {
            if ($expectedCategoryId) {
                $query->orderByRaw(
                    "CASE 
                        WHEN products.category_id = ? AND products.normalized_model = ? THEN 0
                        WHEN products.normalized_model = ? THEN 1
                        WHEN products.category_id = ? AND products.normalized_model LIKE ? THEN 2
                        WHEN products.normalized_model LIKE ? THEN 3
                        WHEN products.category_id = ? AND products.normalized_full_name = ? THEN 4
                        WHEN products.normalized_full_name = ? THEN 5
                        ELSE 6
                     END",
                    [$expectedCategoryId, $normalizedCode, $normalizedCode, $expectedCategoryId, '%' . $normalizedCode . '%', '%' . $normalizedCode . '%', $expectedCategoryId, $normalizedFull, $normalizedFull]
                );
            } else {
                $query->orderByRaw(
                    "CASE 
                        WHEN products.normalized_model = ? THEN 0
                        WHEN products.normalized_model LIKE ? THEN 1
                        WHEN products.normalized_full_name = ? THEN 2
                        ELSE 3
                     END",
                    [$normalizedCode, '%' . $normalizedCode . '%', $normalizedFull]
                );
            }
        }
        
        $products = $query->limit(10)->get();
        
        return $products->map(function ($product) use ($expectedCategoryId) {
            $confidence = 1.0; // Start with 100% for exact match
            $confidence = $this->applyCategoryPenalty($confidence, $expectedCategoryId, $product->category_id);
            
            return [
                'product_id' => $product->id,
                'psm_code' => $product->psm_code,
                'confidence' => $confidence,
                'match_type' => 'exact_description',
            ];
        });
    }
    
    /**
     * Extract model number from description (e.g., "DN-360", "DN360", "EOS R5")
     * Uses ProductNormalizer for consistency
     */
    protected function extractModelNumber(string $description): ?string
    {
        return ProductNormalizer::extractModelCode($description);
    }
    
    /**
     * Find products with exact or near-exact model number match
     * Uses normalized_model column for efficient matching
     */
    protected function findExactModelMatches(string $modelNumber, ImportSessionItem $item, ?int $expectedCategoryId = null): Collection
    {
        // Normalize model code using ProductNormalizer
        $normalizedCode = ProductNormalizer::normalizeCode($modelNumber);
        
        if (!$normalizedCode || !ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            return collect();
        }
        
        // Build query using normalized_model column
        $query = Product::with(['brand', 'category'])
            ->where(function ($q) use ($normalizedCode) {
                // Exact match on normalized_model
                $q->where('normalized_model', $normalizedCode)
                  // Also check if normalized_model contains the code (handles prefixes like "EV-DML1122")
                  ->orWhere('normalized_model', 'LIKE', '%' . $normalizedCode . '%');
            });
        
        // Prioritize category matches if expected category is known
        if ($expectedCategoryId) {
            $query->orderByRaw(
                "CASE 
                    WHEN category_id = ? AND normalized_model = ? THEN 0
                    WHEN normalized_model = ? THEN 1
                    WHEN category_id = ? AND normalized_model LIKE ? THEN 2
                    ELSE 3
                 END",
                [$expectedCategoryId, $normalizedCode, $normalizedCode, $expectedCategoryId, '%' . $normalizedCode . '%']
            );
        } else {
            $query->orderByRaw(
                "CASE WHEN normalized_model = ? THEN 0 ELSE 1 END",
                [$normalizedCode]
            );
        }
        
        return $query->get()->map(function ($product) use ($normalizedCode, $expectedCategoryId) {
            // Start with base confidence
            $confidence = $product->normalized_model === $normalizedCode ? 0.95 : 0.90;
            
            // Apply category penalty
            $confidence = $this->applyCategoryPenalty($confidence, $expectedCategoryId, $product->category_id);
            
            return [
                'product_id' => $product->id,
                'psm_code' => $product->psm_code,
                'confidence' => $confidence,
                'match_type' => $product->normalized_model === $normalizedCode ? 'exact_model' : 'partial_model',
            ];
        });
    }
    
    /**
     * Find products by PSM code when model matches
     * Uses normalized_model for efficient matching
     * Applies category penalties to prevent cross-category false matches
     */
    protected function findByPsmCodeViaModel(string $modelNumber, ?int $expectedCategoryId = null): Collection
    {
        $normalizedCode = ProductNormalizer::normalizeCode($modelNumber);
        
        if (!$normalizedCode || !ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            return collect();
        }
        
        // Find a product with matching normalized model that has a PSM code
        // Prioritize products with matching category if expected category is known
        $query = Product::where(function ($q) use ($normalizedCode) {
            $q->where('normalized_model', $normalizedCode)
              ->orWhere('normalized_model', 'LIKE', '%' . $normalizedCode . '%');
        })
        ->whereNotNull('psm_code');
        
        if ($expectedCategoryId) {
            $query->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$expectedCategoryId]);
        }
        
        $product = $query->first();
        
        if ($product && $product->psm_code) {
            // Find ALL products with the same PSM code
            return Product::where('psm_code', $product->psm_code)
                ->with('category')
                ->get()
                ->map(function ($p) use ($expectedCategoryId) {
                    $confidence = 1.0; // PSM code match starts at 100%
                    // Apply category penalty - PSM codes should typically match category, but verify
                    $confidence = $this->applyCategoryPenalty($confidence, $expectedCategoryId, $p->category_id);
                    
                    return [
                        'product_id' => $p->id,
                        'psm_code' => $p->psm_code,
                        'confidence' => $confidence,
                        'match_type' => 'psm_code',
                    ];
                });
        }
        
        return collect();
    }
    
    /**
     * Normalized similarity matching using normalized columns
     * Handles variations like "DML-1122", "EV-DML1122", "Apogee SSM -"
     */
    protected function findNormalizedSimilarityMatches(ImportSessionItem $item, ?int $expectedCategoryId = null): Collection
    {
        $description = $item->original_description;
        
        // Extract and normalize model code
        $modelCode = ProductNormalizer::extractModelCode($description);
        $normalizedCode = $modelCode ? ProductNormalizer::normalizeCode($modelCode) : null;
        
        // Normalize full description
        $normalizedFull = ProductNormalizer::normalizeFullName(null, $description);
        
        // Build query using normalized columns
        $query = Product::query()->with(['brand', 'category']);
        
        if ($normalizedCode && ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            // Use normalized_model for efficient matching
            $query->where(function ($q) use ($normalizedCode) {
                $q->where('normalized_model', $normalizedCode)
                  ->orWhere('normalized_model', 'LIKE', '%' . $normalizedCode . '%');
            });
            
            // Prioritize category matches if expected category is known
            if ($expectedCategoryId) {
                $query->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$expectedCategoryId]);
            }
            
            $products = $query->limit(100)->get();
        } else {
            // Fallback to normalized_full_name search
            if ($normalizedFull) {
                $query->where(function ($q) use ($normalizedFull) {
                    $q->where('normalized_full_name', 'LIKE', '%' . $normalizedFull . '%');
                });
            }
            
            // Prioritize category matches if expected category is known
            if ($expectedCategoryId) {
                $query->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$expectedCategoryId]);
            }
            
            $products = $query->limit(500)->get();
        }
        
        return $products->map(function ($product) use ($normalizedCode, $normalizedFull, $expectedCategoryId) {
            $confidence = 0.0;
            
            // Calculate confidence based on normalized matches
            if ($normalizedCode && $product->normalized_model) {
                if ($product->normalized_model === $normalizedCode) {
                    $confidence = 0.95; // Exact code match
                } elseif (str_contains($product->normalized_model, $normalizedCode) || 
                          str_contains($normalizedCode, $product->normalized_model)) {
                    $confidence = 0.85; // Partial code match
                }
            }
            
            // Also check normalized_full_name
            if ($normalizedFull && $product->normalized_full_name) {
                $fullConfidence = $this->calculateNormalizedSimilarity($normalizedFull, $product->normalized_full_name);
                $confidence = max($confidence, $fullConfidence);
            }
            
            // Apply category penalty
            if ($confidence > 0) {
                $confidence = $this->applyCategoryPenalty($confidence, $expectedCategoryId, $product->category_id);
            }
            
            if ($confidence >= 0.70) {
                return [
                    'product_id' => $product->id,
                    'psm_code' => $product->psm_code,
                    'confidence' => $confidence,
                    'match_type' => 'normalized_similarity',
                ];
            }
            
            return null;
        })->filter();
    }
    
    /**
     * Calculate similarity between two normalized strings
     */
    protected function calculateNormalizedSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        
        // If one contains the other, high confidence
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $shorter = strlen($a) <= strlen($b) ? $a : $b;
            $longer = strlen($a) > strlen($b) ? $a : $b;
            return strlen($shorter) / strlen($longer);
        }
        
        // Use similar_text for fuzzy matching
        similar_text($a, $b, $percent);
        return $percent / 100;
    }
    
    /**
     * Enhanced description-based matching for products without model numbers
     * Uses full-text search on product descriptions and brand names
     */
    protected function findByDescription(ImportSessionItem $item, ?int $expectedCategoryId = null): Collection
    {
        $normalized = $this->normalizeDescription($item->original_description);
        $keyTerms = $this->extractKeyTerms($normalized);
        
        if (empty($keyTerms)) {
            return collect();
        }
        
        // Build query to search for products containing key terms in model OR brand name
        $query = Product::query()->with(['brand', 'category']);
        
        // Search for products that contain multiple key terms (better matches)
        $query->where(function ($q) use ($keyTerms) {
            foreach ($keyTerms as $term) {
                if (strlen($term) >= 4) { // Only use terms 4+ chars for better precision
                    $q->orWhere('model', 'LIKE', "%{$term}%")
                      ->orWhereHas('brand', function ($brandQuery) use ($term) {
                          $brandQuery->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($term) . '%']);
                      });
                }
            }
        });
        
        // Prioritize category matches if expected category is known
        if ($expectedCategoryId) {
            $query->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$expectedCategoryId]);
        }
        
        $products = $query->limit(200)->get();
        
        return $products->map(function ($product) use ($normalized, $expectedCategoryId) {
            // Build full product name: brand + model for comparison
            $productFullName = trim(($product->brand->name ?? '') . ' ' . $product->model);
            $productNormalized = $this->normalizeDescription($productFullName);
            
            // Also compare just the model
            $productModelNormalized = $this->normalizeDescription($product->model);
            
            // Calculate similarity for both full name and model
            $fullNameConfidence = $this->calculateSimilarity($normalized, $productNormalized);
            $modelConfidence = $this->calculateSimilarity($normalized, $productModelNormalized);
            
            // Use the higher confidence
            $confidence = max($fullNameConfidence, $modelConfidence);
            
            // Apply category penalty
            $confidence = $this->applyCategoryPenalty($confidence, $expectedCategoryId, $product->category_id);
            
            // For description-only matches, require higher confidence (0.75+)
            if ($confidence >= 0.75) {
                return [
                    'product_id' => $product->id,
                    'psm_code' => $product->psm_code,
                    'confidence' => $confidence,
                    'match_type' => 'description_match',
                ];
            }
            
            return null;
        })->filter();
    }
    
    /**
     * Fallback fuzzy matching (only used if no high-confidence matches found)
     * This is less accurate but catches edge cases
     */
    protected function findFuzzyMatches(ImportSessionItem $item, ?int $expectedCategoryId = null): Collection
    {
        $normalized = $this->normalizeDescription($item->original_description);
        
        // Get products - limit to prevent performance issues
        // Prioritize expected category if known
        $query = Product::select('id', 'model', 'psm_code', 'category_id')
            ->with('category');
        
        if ($expectedCategoryId) {
            $query->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$expectedCategoryId]);
        }
        
        $products = $query->limit(500)->get();
        
        return $products->map(function ($product) use ($normalized, $expectedCategoryId) {
            $productNormalized = $this->normalizeDescription($product->model);
            $confidence = $this->calculateSimilarity($normalized, $productNormalized);
            
            // Apply category penalty
            $confidence = $this->applyCategoryPenalty($confidence, $expectedCategoryId, $product->category_id);
            
            if ($confidence >= 0.70) {
                return [
                    'product_id' => $product->id,
                    'psm_code' => $product->psm_code,
                    'confidence' => $confidence,
                    'match_type' => 'fuzzy',
                ];
            }
            
            return null;
        })->filter();
    }
    
    /**
     * Enhanced normalization that handles brand variations
     * Uses ProductNormalizer for consistency
     */
    protected function normalizeDescription(string $text): string
    {
        return ProductNormalizer::normalizeDescription($text);
    }
    
    /**
     * Improved similarity calculation using term matching + string similarity
     * More lenient with extra words (handles metadata like source names)
     */
    protected function calculateSimilarity(string $a, string $b): float
    {
        // Extract key terms (model numbers, significant words)
        $aTerms = $this->extractKeyTerms($a);
        $bTerms = $this->extractKeyTerms($b);
        
        // Calculate term overlap
        $termMatch = 0.0;
        if (!empty($aTerms) && !empty($bTerms)) {
            $intersection = count(array_intersect($aTerms, $bTerms));
            $union = count(array_unique(array_merge($aTerms, $bTerms)));
            
            // If all terms from the shorter string are in the longer string, 
            // it's a good match (handles cases like "apogee ssm" vs "apogee ssm amazon")
            $shorterTerms = count($aTerms) <= count($bTerms) ? $aTerms : $bTerms;
            $longerTerms = count($aTerms) > count($bTerms) ? $aTerms : $bTerms;
            
            if (!empty($shorterTerms)) {
                $shorterInLonger = count(array_intersect($shorterTerms, $longerTerms));
                $coverage = $shorterInLonger / count($shorterTerms);
                
                // If shorter string is fully contained in longer, boost the score
                if ($coverage >= 1.0) {
                    $termMatch = min(1.0, ($intersection / max(count($shorterTerms), 1)) * 1.1);
                } else {
                    $termMatch = $union > 0 ? $intersection / $union : 0.0;
                }
            } else {
                $termMatch = $union > 0 ? $intersection / $union : 0.0;
            }
        }
        
        // Calculate text similarity using similar_text
        $textSimilarity = 0.0;
        if (!empty($a) && !empty($b)) {
            similar_text($a, $b, $textPercent);
            $textSimilarity = $textPercent / 100;
            
            // Boost similarity if one string contains the other (handles metadata suffixes)
            $aInB = str_contains($b, $a);
            $bInA = str_contains($a, $b);
            if ($aInB || $bInA) {
                $textSimilarity = min(1.0, $textSimilarity * 1.15);
            }
        }
        
        // Weighted combination: 60% term match (more important), 40% text similarity
        return ($termMatch * 0.6) + ($textSimilarity * 0.4);
    }
    
    /**
     * Extract key terms from text (model numbers and significant words)
     */
    protected function extractKeyTerms(string $text): array
    {
        $terms = [];
        
        // Extract model numbers (e.g., DN360, EOS R5, R2, X1)
        preg_match_all('/\b[A-Z]{1,5}[-\s]?\d{1,5}\b/i', $text, $models);
        $terms = array_merge($terms, array_map('strtoupper', $models[0]));
        
        // Extract significant words (4+ characters, not common stop words)
        preg_match_all('/\b[a-z]{4,}\b/i', $text, $words);
        $stopWords = ['this', 'that', 'with', 'from', 'have', 'been', 'were', 'they', 'their'];
        $significantWords = array_filter($words[0], function ($word) use ($stopWords) {
            return !in_array(strtolower($word), $stopWords);
        });
        $terms = array_merge($terms, array_map('strtolower', $significantWords));
        
        return array_unique($terms);
    }
    
    /**
     * Extract or infer category ID from product description
     * Looks for category-specific keywords like "lighting console", "amplifier", etc.
     * 
     * @param string $description Product description
     * @return int|null Category ID if inferred, null otherwise
     */
    protected function extractCategoryFromDescription(string $description): ?int
    {
        $normalized = strtolower($description);
        
        // Define category keywords mapping
        // Keywords that strongly indicate a category
        $categoryKeywords = [
            'lighting' => [
                'lighting console', 'light console', 'grandma', 'grand ma', 'grandma3', 'grand ma 3',
                'lighting desk', 'lighting controller', 'lighting board', 'dmx console',
                'fixture', 'light', 'lamp', 'led', 'moving head', 'beam', 'wash',
            ],
            'sound' => [
                'amplifier', 'amp', 'power amp', 'power amplifier', 'multichannel amplifier',
                'compressor', 'gate', 'eq', 'equalizer', 'mixer', 'audio mixer',
                'speaker', 'microphone', 'mic', 'monitor', 'subwoofer',
            ],
            'video' => [
                'projector', 'display', 'screen', 'monitor', 'camera', 'video mixer',
                'switcher', 'encoder', 'decoder',
            ],
        ];
        
        // Map category keywords to category names (case-insensitive search in DB)
        $categoryNameMap = [
            'lighting' => 'Lighting',
            'sound' => 'Sound',
            'video' => 'Video',
        ];
        
        // Check for category keywords
        foreach ($categoryKeywords as $categoryKey => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, strtolower($keyword))) {
                    // Try to find the category by name
                    $categoryName = $categoryNameMap[$categoryKey] ?? null;
                    if ($categoryName) {
                        $category = Category::where(function ($q) use ($categoryName) {
                            $q->whereRaw('LOWER(name) = ?', [strtolower($categoryName)])
                              ->orWhere('name', 'LIKE', "%{$categoryName}%");
                        })->first();
                        if ($category) {
                            return $category->id;
                        }
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Apply category penalty to confidence score
     * Heavily penalizes matches across incompatible categories
     * Only assigns 100% confidence when categories align
     * 
     * @param float $confidence Base confidence score (0.0 to 1.0)
     * @param int|null $expectedCategoryId Expected category ID from description
     * @param int|null $productCategoryId Actual product category ID
     * @return float Adjusted confidence score
     */
    protected function applyCategoryPenalty(float $confidence, ?int $expectedCategoryId, ?int $productCategoryId): float
    {
        // If no expected category, return confidence as-is (no penalty)
        if (!$expectedCategoryId) {
            return $confidence;
        }
        
        // If product has no category, apply small penalty (uncertainty)
        if (!$productCategoryId) {
            return $confidence * 0.85; // 15% penalty for missing category
        }
        
        // Categories match - no penalty, can achieve 100% confidence
        if ($expectedCategoryId === $productCategoryId) {
            return $confidence;
        }
        
        // Categories don't match - apply heavy penalty
        // For cross-category matches, heavily reduce confidence
        // Especially important for incompatible categories like Lighting vs Sound
        
        // Load category names to check for incompatible pairs
        $expectedCategory = Category::find($expectedCategoryId);
        $productCategory = Category::find($productCategoryId);
        
        if ($expectedCategory && $productCategory) {
            $expectedName = strtolower($expectedCategory->name);
            $productName = strtolower($productCategory->name);
            
            // Define incompatible category pairs (mutually exclusive)
            $incompatiblePairs = [
                ['lighting', 'sound'],
                ['sound', 'lighting'],
                ['lighting', 'video'],
                ['video', 'lighting'],
                // Add more as needed
            ];
            
            // Check if this is an incompatible pair
            $isIncompatible = false;
            foreach ($incompatiblePairs as $pair) {
                if (($expectedName === $pair[0] && $productName === $pair[1]) ||
                    ($expectedName === $pair[1] && $productName === $pair[0])) {
                    $isIncompatible = true;
                    break;
                }
            }
            
            // Heavy penalty for incompatible categories (prevents 100% confidence)
            if ($isIncompatible) {
                // For incompatible categories, maximum confidence is 60%
                // This ensures they never show as 100% matches
                return min($confidence * 0.40, 0.60);
            }
            
            // For other category mismatches, apply moderate penalty
            // Still allows some confidence but not 100%
            return min($confidence * 0.70, 0.85);
        }
        
        // Fallback: apply standard penalty for category mismatch
        return min($confidence * 0.70, 0.85);
    }
}

