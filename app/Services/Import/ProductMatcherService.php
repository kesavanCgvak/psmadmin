<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ImportSessionItem;
use App\Support\ProductNormalizer;
use Illuminate\Support\Collection;

class ProductMatcherService
{
    /**
     * Product matching based on Brand OR Product Name/Model
     * 
     * Matching Rules:
     * 1. A product matches if brand name matches OR product name/model words match
     * 2. Brand and product name are independent - both are equal matching options
     * 3. Category/Sub-Category are completely ignored
     * 4. Word order, hyphens, extra spaces are ignored
     * 5. Normalize input values before comparison
     * 6. Confidence based on similarity score
     * 
     * @param ImportSessionItem $item
     * @param float $minConfidence Minimum confidence threshold (0.0 to 1.0)
     * @return Collection Collection of matches with product_id, psm_code, confidence, match_type
     */
    public function findMatches(ImportSessionItem $item, float $minConfidence = 0.70): Collection
    {
        $description = $item->original_description;
        
        // STEP 1: Extract brand and product name words
        $extractedBrandId = $this->extractBrandFromDescription($description);
        $extractedBrandName = $extractedBrandId ? $this->getBrandName($extractedBrandId) : null;
        
        // Extract meaningful words from description (excluding brand name)
        $searchWords = $this->extractWordsForMatching($description);
        if ($extractedBrandName) {
            // Remove brand name from search words
            // Handle both full brand name and individual words if brand name has multiple words
            $brandNameLower = strtolower(trim($extractedBrandName));
            $brandWords = explode(' ', $brandNameLower);
            
            $searchWords = array_filter($searchWords, function ($word) use ($brandNameLower, $brandWords) {
                $wordLower = strtolower($word);
                // Remove if matches full brand name or any individual brand word
                if ($wordLower === $brandNameLower) {
                    return false;
                }
                foreach ($brandWords as $brandWord) {
                    if ($wordLower === trim($brandWord)) {
                        return false;
                    }
                }
                return true;
            });
            // Re-index array after filtering
            $searchWords = array_values($searchWords);
        }
        
        // STEP 2: Build query - match by brand OR product name/model words
        // Category and sub-category are completely ignored
        $query = Product::query()->with('brand');
        
        $hasBrandMatch = false;
        $hasWordMatch = false;
        
        // Build the WHERE clause: brand_id = X OR (model contains word1 OR word2 OR ...)
        // If brand is found, prioritize brand matches, but also allow word matches
        // If no brand found, search by words only
        $query->where(function ($q) use ($extractedBrandId, $searchWords, &$hasBrandMatch, &$hasWordMatch) {
            // Option 1: Match by brand (if brand was extracted) - PRIORITY
            if ($extractedBrandId) {
                // Primary: exact brand match
                $q->where('brand_id', $extractedBrandId);
                $hasBrandMatch = true;
            }
            
            // Option 2: Match by product name/model words (if we have search words)
            // This is secondary/fallback - allows matching products from other brands too
            if (!empty($searchWords)) {
                // Only add OR clause if we also have brand match
                // This ensures we get brand matches AND word matches from other brands
                if ($extractedBrandId) {
                    $q->orWhere(function ($subQ) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $wordLower = strtolower($word);
                            // Match word anywhere in product model (normalized, case-insensitive)
                            $subQ->orWhereRaw('LOWER(model) LIKE ?', ['%' . $wordLower . '%']);
                        }
                    });
                } else {
                    // No brand found - search by words only
                    $q->where(function ($subQ) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $wordLower = strtolower($word);
                            // Match word anywhere in product model (normalized, case-insensitive)
                            $subQ->orWhereRaw('LOWER(model) LIKE ?', ['%' . $wordLower . '%']);
                        }
                    });
                }
                $hasWordMatch = true;
            }
        });
        
        // If neither brand nor words were found, cannot match
        if (!$hasBrandMatch && !$hasWordMatch) {
            return collect();
        }
        
        // Order by brand match first (if brand was found), then by relevance
        if ($extractedBrandId) {
            $query->orderByRaw('CASE WHEN brand_id = ? THEN 0 ELSE 1 END', [$extractedBrandId]);
        }
        
        // Get products that match either brand OR product name/model words
        $products = $query->limit(500)->get();
        
        // STEP 3: Calculate confidence for each product
        $matches = $products->map(function ($product) use ($searchWords, $extractedBrandId, $extractedBrandName) {
            $confidence = 0.0;
            $matchTypes = [];
            $matchCount = 0;
            
            // Calculate brand match score (independent)
            $brandScore = 0.0;
            $hasBrandMatch = false;
            if ($extractedBrandId && $product->brand_id === $extractedBrandId) {
                $brandScore = 1.0; // Full score for brand match
                $hasBrandMatch = true;
                $matchTypes[] = 'brand_match';
            }
            
            // Calculate product name/model word match score (independent)
            $wordScore = 0.0;
            if (!empty($searchWords)) {
                $productWords = $this->extractWordsForMatching($product->model);
                
                // Count matching words (case-insensitive, normalized)
                $searchWordsLower = array_map('strtolower', $searchWords);
                $productWordsLower = array_map('strtolower', $productWords);
                
                $matchingWords = array_intersect($searchWordsLower, $productWordsLower);
                $matchCount = count($matchingWords);
                $totalSearchWords = count($searchWords);
                
                if ($totalSearchWords > 0) {
                    // Base score: percentage of search words that matched
                    $wordScore = $matchCount / $totalSearchWords;
                    
                    // Boost score based on number of matching words
                    if ($matchCount >= 4) {
                        $wordScore = min(1.0, $wordScore + 0.30); // Strong match - increased boost
                    } elseif ($matchCount >= 3) {
                        $wordScore = min(1.0, $wordScore + 0.25); // Good match - increased boost
                    } elseif ($matchCount >= 2) {
                        $wordScore = min(1.0, $wordScore + 0.20); // Decent match - increased boost
                    } elseif ($matchCount >= 1) {
                        $wordScore = min(1.0, $wordScore + 0.15); // Weak match - increased boost
                    }
                    
                    if ($matchCount > 0) {
                        $matchTypes[] = 'word_match';
                        if ($matchCount === $totalSearchWords && $totalSearchWords >= 3) {
                            $matchTypes[] = 'exact_word_match';
                        }
                    }
                }
            }
            
            // Combine scores: prioritize brand matches, but also consider word matches
            // If brand matches, start with high confidence
            if ($hasBrandMatch) {
                // Brand match = base confidence of 0.70
                $confidence = 0.70;
                
                // Add word match bonus if words also match
                if ($matchCount > 0) {
                    // Strong boost for word matches when brand also matches
                    if ($matchCount >= 3) {
                        $confidence = min(1.0, $confidence + 0.30); // Strong word match
                    } elseif ($matchCount >= 2) {
                        $confidence = min(1.0, $confidence + 0.20); // Good word match
                    } elseif ($matchCount >= 1) {
                        $confidence = min(1.0, $confidence + 0.10); // Some word match
                    }
                }
            } else {
                // No brand match - rely on word matching only
                $confidence = $wordScore;
                
                // Lower threshold for word-only matches (at least 0.50 if 2+ words match)
                if ($matchCount >= 2) {
                    $confidence = max(0.50, $confidence);
                }
            }
            
            // Determine primary match type
            $matchType = 'unknown';
            if (in_array('exact_word_match', $matchTypes)) {
                $matchType = 'exact_word_match';
            } elseif (in_array('brand_match', $matchTypes) && in_array('word_match', $matchTypes)) {
                $matchType = 'brand_and_word_match';
            } elseif (in_array('brand_match', $matchTypes)) {
                $matchType = 'brand_match';
            } elseif (in_array('word_match', $matchTypes)) {
                $matchType = 'word_match';
            }
            
            return [
                'product_id' => $product->id,
                'psm_code' => $product->psm_code,
                'confidence' => round($confidence, 2),
                'match_type' => $matchType,
                'brand_match' => $brandScore > 0,
                'word_match' => $wordScore > 0,
                'brand_score' => round($brandScore, 2),
                'word_score' => round($wordScore, 2),
            ];
        });
        
        // Filter by minimum confidence threshold
        // Lower threshold to ensure we catch brand matches and word matches
        // Brand matches get at least 0.70, word matches need at least 0.50
        $effectiveThreshold = 0.50; // Always use 0.50 to catch all potential matches
        $matches = $matches->where('confidence', '>=', $effectiveThreshold);
        
        // Sort by confidence (descending)
        // Prioritize matches with both brand AND word match, then by confidence
        $matches = $matches->sortByDesc(function ($match) {
            // Higher priority if both brand and word match
            $bothMatchBonus = ($match['brand_match'] && $match['word_match']) ? 1.0 : 0.0;
            return [$bothMatchBonus, $match['confidence']];
        });
        
        return $matches->take(10)->values();
    }
    
    /**
     * Extract meaningful words from text for matching
     * Normalizes input (removes special characters, hyphens, extra spaces)
     * 
     * @param string $text
     * @return array Array of normalized words
     */
    protected function extractWordsForMatching(string $text): array
    {
        $words = [];
        $normalized = strtolower(trim($text));
        
        // Remove special characters but keep alphanumeric and spaces
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized);
        
        // Normalize multiple spaces to single space
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        // Stop words to ignore (common words that don't help with matching)
        $stopWords = [
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with',
            'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been', 'be', 'have', 'has', 'had',
            'do', 'does', 'did', 'will', 'would', 'should', 'could', 'may', 'might', 'must',
            'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they',
            'what', 'which', 'who', 'when', 'where', 'why', 'how', 'all', 'each', 'every',
            'both', 'few', 'more', 'most', 'other', 'some', 'such', 'only', 'own', 'same',
            'so', 'than', 'too', 'very', 'can', 'just', 'should', 'now'
        ];
        
        // Extract all words
        $allWords = explode(' ', $normalized);
        
        // Filter and normalize words
        foreach ($allWords as $word) {
            $word = trim($word);
            
            // Skip empty words
            if (empty($word)) {
                continue;
            }
            
            // Skip stop words
            if (in_array($word, $stopWords)) {
                continue;
            }
            
            // Skip very short words (less than 2 chars)
            if (strlen($word) < 2) {
                continue;
            }
            
            // Keep the word (store in lowercase for consistency)
            $words[] = strtolower($word);
        }
        
        // Remove duplicates
        return array_unique($words);
    }
    
    /**
     * Extract brand ID from product description
     * Case-insensitive, trimmed, normalized matching
     * Prioritizes brands that appear earlier in description (brands typically come first)
     * 
     * @param string $description Product description
     * @return int|null Brand ID if found, null otherwise
     */
    protected function extractBrandFromDescription(string $description): ?int
    {
        $normalized = strtolower(trim($description));
        
        // Get all brands
        $brands = \App\Models\Brand::all();
        
        $foundBrands = [];
        
        // Find all matching brands and their positions
        foreach ($brands as $brand) {
            $brandName = strtolower(trim($brand->name));
            
            // Normalize brand name (remove special characters, extra spaces)
            $brandNameNormalized = preg_replace('/[^a-z0-9\s]/', ' ', $brandName);
            $brandNameNormalized = preg_replace('/\s+/', ' ', $brandNameNormalized);
            $brandNameNormalized = trim($brandNameNormalized);
            
            // Check if brand name appears in description using word boundary
            // IMPORTANT: Brands typically appear at the start, so check if it's at the beginning
            $position = null;
            $matches = false;
            $isAtStart = false;
            
            // Try normalized brand name first
            $pattern = '/\b' . preg_quote($brandNameNormalized, '/') . '\b/i';
            if (preg_match($pattern, $normalized, $matchesArray, PREG_OFFSET_CAPTURE)) {
                $matches = true;
                $position = $matchesArray[0][1]; // Get position in string
                // Check if brand appears at the start of the description (typical position)
                $isAtStart = ($position === 0 || 
                             ($position < 10 && preg_match('/^' . preg_quote($brandNameNormalized, '/') . '\b/i', $normalized)));
            }
            
            // Also try original brand name (without normalization)
            if (!$matches && preg_match('/\b' . preg_quote($brandName, '/') . '\b/i', $normalized, $matchesArray, PREG_OFFSET_CAPTURE)) {
                $matches = true;
                $position = $matchesArray[0][1]; // Get position in string
                $isAtStart = ($position === 0 || 
                             ($position < 10 && preg_match('/^' . preg_quote($brandName, '/') . '\b/i', $normalized)));
            }
            
            if ($matches) {
                $foundBrands[] = [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'position' => $position,
                    'length' => strlen($brand->name), // Longer names are often more specific
                    'is_at_start' => $isAtStart, // Bonus for brands at start
                ];
            }
        }
        
        // If no brands found, return null
        if (empty($foundBrands)) {
            return null;
        }
        
        // Prioritize brands that appear earlier in description (brands typically come first)
        // Also prioritize brands at the very start of the description
        // Secondary priority: longer brand names (more specific, e.g., "MAC AURA" vs "AURA")
        usort($foundBrands, function ($a, $b) {
            // First priority: brands at the start (position 0 or near start)
            if ($a['is_at_start'] !== $b['is_at_start']) {
                return $b['is_at_start'] ? 1 : -1;
            }
            // Second priority: position (earlier is better)
            if ($a['position'] !== $b['position']) {
                return $a['position'] <=> $b['position'];
            }
            // Third priority: longer name (more specific)
            return $b['length'] <=> $a['length'];
        });
        
        // Return the brand that appears earliest (and is longest if tied on position)
        return $foundBrands[0]['id'];
    }
    
    /**
     * Get brand name by ID
     * 
     * @param int $brandId
     * @return string|null
     */
    protected function getBrandName(int $brandId): ?string
    {
        $brand = \App\Models\Brand::find($brandId);
        return $brand ? $brand->name : null;
    }
}