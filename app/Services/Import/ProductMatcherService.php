<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ImportSessionItem;
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
        
        // Strategy 0: EXACT MATCH FIRST (case-insensitive, trimmed)
        // This catches products like "CHAUVET ROGUE R2 BEAM FIXTURE" matching exactly
        $exactMatches = $this->findExactDescriptionMatches($item);
        $matches = $matches->merge($exactMatches);
        
        // If we found exact matches, return them (they're 100% confidence)
        if ($exactMatches->isNotEmpty()) {
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
            $exactModelMatches = $this->findExactModelMatches($modelNumber, $item);
            $matches = $matches->merge($exactModelMatches);
            
            // PSM code lookup - if model matches a product with PSM code, find all products with that PSM code
            // This is critical: PSM code is the universal identifier for the same product
            $psmMatches = $this->findByPsmCodeViaModel($modelNumber);
            $matches = $matches->merge($psmMatches);
        }
        
        // Strategy 2: Normalized similarity matching (handles brand variations)
        // This works for both products with and without model numbers
        $normalizedMatches = $this->findNormalizedSimilarityMatches($item);
        $matches = $matches->merge($normalizedMatches);
        
        // Strategy 3: Enhanced description matching for products without model numbers
        // If no model number found, do a broader search
        if (!$modelNumber) {
            $descriptionMatches = $this->findByDescription($item);
            $matches = $matches->merge($descriptionMatches);
        }
        
        // Strategy 4: Fuzzy match as fallback (only if no high-confidence matches found)
        if ($matches->where('confidence', '>=', 0.85)->isEmpty()) {
            $fuzzyMatches = $this->findFuzzyMatches($item);
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
    protected function findExactDescriptionMatches(ImportSessionItem $item): Collection
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
            ->with('brand');
        
        // Order by exact match first, then contains
        if ($normalizedCode) {
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
        
        $products = $query->limit(10)->get();
        
        return $products->map(function ($product) {
            return [
                'product_id' => $product->id,
                'psm_code' => $product->psm_code,
                'confidence' => 1.0, // Exact match = 100% confidence
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
    protected function findExactModelMatches(string $modelNumber, ImportSessionItem $item): Collection
    {
        // Normalize model code using ProductNormalizer
        $normalizedCode = ProductNormalizer::normalizeCode($modelNumber);
        
        if (!$normalizedCode || !ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            return collect();
        }
        
        // Build query using normalized_model column
        $query = Product::with('brand')
            ->where(function ($q) use ($normalizedCode) {
                // Exact match on normalized_model
                $q->where('normalized_model', $normalizedCode)
                  // Also check if normalized_model contains the code (handles prefixes like "EV-DML1122")
                  ->orWhere('normalized_model', 'LIKE', '%' . $normalizedCode . '%');
            })
            ->orderByRaw(
                "CASE WHEN normalized_model = ? THEN 0 ELSE 1 END",
                [$normalizedCode]
            );
        
        return $query->get()->map(function ($product) use ($normalizedCode) {
            // Exact match gets 95% confidence
            if ($product->normalized_model === $normalizedCode) {
                return [
                    'product_id' => $product->id,
                    'psm_code' => $product->psm_code,
                    'confidence' => 0.95,
                    'match_type' => 'exact_model',
                ];
            }
            
            // Contains match gets 90% confidence
            return [
                'product_id' => $product->id,
                'psm_code' => $product->psm_code,
                'confidence' => 0.90,
                'match_type' => 'partial_model',
            ];
        });
    }
    
    /**
     * Find products by PSM code when model matches
     * Uses normalized_model for efficient matching
     */
    protected function findByPsmCodeViaModel(string $modelNumber): Collection
    {
        $normalizedCode = ProductNormalizer::normalizeCode($modelNumber);
        
        if (!$normalizedCode || !ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            return collect();
        }
        
        // Find a product with matching normalized model that has a PSM code
        $product = Product::where('normalized_model', $normalizedCode)
            ->whereNotNull('psm_code')
            ->first();
        
        // Also try contains match if exact match fails
        if (!$product) {
            $product = Product::where('normalized_model', 'LIKE', '%' . $normalizedCode . '%')
                ->whereNotNull('psm_code')
                ->first();
        }
        
        if ($product && $product->psm_code) {
            // Find ALL products with the same PSM code
            return Product::where('psm_code', $product->psm_code)
                ->get()
                ->map(function ($p) {
                    return [
                        'product_id' => $p->id,
                        'psm_code' => $p->psm_code,
                        'confidence' => 1.0, // PSM code match = 100% confidence
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
    protected function findNormalizedSimilarityMatches(ImportSessionItem $item): Collection
    {
        $description = $item->original_description;
        
        // Extract and normalize model code
        $modelCode = ProductNormalizer::extractModelCode($description);
        $normalizedCode = $modelCode ? ProductNormalizer::normalizeCode($modelCode) : null;
        
        // Normalize full description
        $normalizedFull = ProductNormalizer::normalizeFullName(null, $description);
        
        // Build query using normalized columns
        $query = Product::query()->with('brand');
        
        if ($normalizedCode && ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
            // Use normalized_model for efficient matching
            $query->where(function ($q) use ($normalizedCode) {
                $q->where('normalized_model', $normalizedCode)
                  ->orWhere('normalized_model', 'LIKE', '%' . $normalizedCode . '%');
            });
            $products = $query->limit(100)->get();
        } else {
            // Fallback to normalized_full_name search
            if ($normalizedFull) {
                $query->where(function ($q) use ($normalizedFull) {
                    $q->where('normalized_full_name', 'LIKE', '%' . $normalizedFull . '%');
                });
            }
            $products = $query->limit(500)->get();
        }
        
        return $products->map(function ($product) use ($normalizedCode, $normalizedFull) {
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
    protected function findByDescription(ImportSessionItem $item): Collection
    {
        $normalized = $this->normalizeDescription($item->original_description);
        $keyTerms = $this->extractKeyTerms($normalized);
        
        if (empty($keyTerms)) {
            return collect();
        }
        
        // Build query to search for products containing key terms in model OR brand name
        $query = Product::query()->with('brand');
        
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
        
        $products = $query->limit(200)->get();
        
        return $products->map(function ($product) use ($normalized) {
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
    protected function findFuzzyMatches(ImportSessionItem $item): Collection
    {
        $normalized = $this->normalizeDescription($item->original_description);
        
        // Get products - limit to prevent performance issues
        // In production, you might want to add more filtering here
        $products = Product::select('id', 'model', 'psm_code')
            ->limit(500) // Limit for performance
            ->get();
        
        return $products->map(function ($product) use ($normalized) {
            $productNormalized = $this->normalizeDescription($product->model);
            $confidence = $this->calculateSimilarity($normalized, $productNormalized);
            
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
}

