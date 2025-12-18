<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ImportSessionItem;
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
     * Find exact description matches (case-insensitive, trimmed)
     * This catches products that match exactly like "CHAUVET ROGUE R2 BEAM FIXTURE"
     * Also matches brand + model combinations like "Apogee SSM"
     * Handles metadata in brackets (e.g., "Apogee SSM [Amazon]" matches "Apogee SSM")
     */
    protected function findExactDescriptionMatches(ImportSessionItem $item): Collection
    {
        $description = trim($item->original_description);
        $normalized = $this->normalizeDescription($description);
        
        // Try exact match on model field (case-insensitive, trimmed)
        // Also check brand + model combination using join
        $products = Product::leftJoin('brands', 'products.brand_id', '=', 'brands.id')
            ->where(function ($query) use ($description, $normalized) {
                // Match original description
                $query->whereRaw('LOWER(TRIM(products.model)) = LOWER(?)', [$description])
                      // Match normalized description (strips brackets/metadata)
                      ->orWhereRaw('LOWER(TRIM(products.normalized_model)) = LOWER(?)', [$normalized])
                      // Normalized comparison on model field
                      ->orWhereRaw('LOWER(REPLACE(REPLACE(TRIM(products.model), "  ", " "), "  ", " ")) = LOWER(?)', [$normalized])
                      // Match brand + model combination (original)
                      ->orWhereRaw('LOWER(TRIM(CONCAT_WS(\' \', brands.name, products.model))) = LOWER(?)', [$description])
                      // Match brand + model combination (normalized - strips brackets)
                      ->orWhereRaw('LOWER(TRIM(CONCAT_WS(\' \', brands.name, products.model))) = LOWER(?)', [$normalized])
                      // Also normalize the product side for comparison (handles cases where product has brackets)
                      ->orWhereRaw('LOWER(TRIM(REPLACE(REPLACE(REPLACE(CONCAT_WS(\' \', brands.name, products.model), \'[\', \' \'), \']\', \' \'), \'  \', \' \'))) = LOWER(?)', [$normalized]);
            })
            ->select('products.*')
            ->with('brand')
            ->get();
        
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
     */
    protected function extractModelNumber(string $description): ?string
    {
        // Pattern: 1-5 letters + optional separator + 1-5 digits
        // Matches: DN-360, DN360, DN 360, EOS R5, R2, X1, etc.
        if (preg_match('/\b([A-Z]{1,5}[-\s]?\d{1,5})\b/i', $description, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Find products with exact or near-exact model number match
     * Handles brand variations: Klark-Teknik, KT, Klark Teknik all match
     */
    protected function findExactModelMatches(string $modelNumber, ImportSessionItem $item): Collection
    {
        // Normalize model: remove separators and spaces for comparison
        // "DN-360" -> "DN360", "DN 360" -> "DN360"
        $normalizedModel = preg_replace('/[-\s]/', '', strtoupper($modelNumber));
        
        // Build query to find products with matching model
        $query = Product::with('brand')
            ->where(function ($q) use ($normalizedModel, $modelNumber) {
                // Match exact normalized model in product.model field
                $q->whereRaw('UPPER(REPLACE(REPLACE(model, "-", ""), " ", "")) LIKE ?', ["%{$normalizedModel}%"])
                  // Also match original model number pattern
                  ->orWhere('model', 'LIKE', "%{$modelNumber}%");
            });
        
        // Apply query and map results
        return $query->get()->map(function ($product) use ($normalizedModel, $modelNumber) {
            // Normalize product model for comparison
            $productModel = preg_replace('/[-\s]/', '', strtoupper($product->model));
            
            // Exact match (normalized) gets 95% confidence
            if ($productModel === $normalizedModel) {
                return [
                    'product_id' => $product->id,
                    'psm_code' => $product->psm_code,
                    'confidence' => 0.95,
                    'match_type' => 'exact_model',
                ];
            }
            
            // Check if product model contains the extracted model number
            if (stripos($product->model, $modelNumber) !== false) {
                return [
                    'product_id' => $product->id,
                    'psm_code' => $product->psm_code,
                    'confidence' => 0.90,
                    'match_type' => 'partial_model',
                ];
            }
            
            // Normalized partial match gets 85% confidence
            if (str_contains($productModel, $normalizedModel) || str_contains($normalizedModel, $productModel)) {
                return [
                    'product_id' => $product->id,
                    'psm_code' => $product->psm_code,
                    'confidence' => 0.85,
                    'match_type' => 'normalized_partial',
                ];
            }
            
            return null;
        })->filter();
    }
    
    /**
     * Find products by PSM code when model matches
     * This is the KEY feature: PSM codes connect all variants of the same product
     * Example: "Klark-Teknik DN-360" and "KT DN360" both have PSM code "PSM00123"
     */
    protected function findByPsmCodeViaModel(string $modelNumber): Collection
    {
        $normalizedModel = preg_replace('/[-\s]/', '', strtoupper($modelNumber));
        
        // Find a product with matching model that has a PSM code
        $product = Product::whereRaw('UPPER(REPLACE(REPLACE(model, "-", ""), " ", "")) LIKE ?', 
                ["%{$normalizedModel}%"])
            ->whereNotNull('psm_code')
            ->first();
        
        if ($product && $product->psm_code) {
            // Find ALL products with the same PSM code
            // These are all variants/descriptions of the same product
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
     * Normalized similarity matching with brand awareness
     * Handles brand variations: "Klark-Teknik" = "KT" = "Klark Teknik"
     * Also matches brand name + model combinations like "Apogee SSM"
     */
    protected function findNormalizedSimilarityMatches(ImportSessionItem $item): Collection
    {
        $normalized = $this->normalizeDescription($item->original_description);
        $modelNumber = $this->extractModelNumber($item->original_description);
        
        // Build query - if we have a model number, use it to narrow search
        $query = Product::query()->with('brand');
        
        if ($modelNumber) {
            $modelNorm = preg_replace('/[-\s]/', '', strtoupper($modelNumber));
            $query->whereRaw('UPPER(REPLACE(REPLACE(model, "-", ""), " ", "")) LIKE ?', ["%{$modelNorm}%"]);
            // With model number, limit to 100 products
            $products = $query->limit(100)->get();
        } else {
            // Without model number, search more broadly but still limit for performance
            // Extract key terms from description to narrow search
            $keyTerms = $this->extractKeyTerms($normalized);
            if (!empty($keyTerms)) {
                // Search for products containing key terms in model OR brand name
                $query->where(function ($q) use ($keyTerms) {
                    foreach ($keyTerms as $term) {
                        if (strlen($term) >= 3) { // Only use terms 3+ chars
                            $q->orWhere('model', 'LIKE', "%{$term}%")
                              ->orWhereHas('brand', function ($brandQuery) use ($term) {
                                  $brandQuery->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($term) . '%']);
                              });
                        }
                    }
                });
            }
            // Without model number, search more products (500) to find matches
            $products = $query->limit(500)->get();
        }
        
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
     * Removes metadata in brackets (e.g., [Amazon], [Fox]) as they're not part of product name
     */
    protected function normalizeDescription(string $text): string
    {
        $text = strtolower($text);
        
        // Remove bracketed content (metadata like [Amazon], [Fox], etc.)
        // This handles cases like "Apogee SSM [Amazon]" -> "Apogee SSM"
        $text = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $text);
        
        // Remove parenthesized content that looks like metadata
        // This handles cases like "Product Name (Source)" -> "Product Name"
        $text = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $text);
        
        // Normalize brand names - common variations
        $text = preg_replace('/\bklark[-\s]?teknik\b/i', 'klarkteknik', $text);
        $text = preg_replace('/\bkt\b/i', 'klarkteknik', $text);
        
        // Normalize common abbreviations
        $text = preg_replace('/\bprofessional\b/i', 'pro', $text);
        $text = preg_replace('/\bequalizer\b/i', 'eq', $text);
        
        // Remove special characters but keep spaces
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));
        
        return $text;
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

