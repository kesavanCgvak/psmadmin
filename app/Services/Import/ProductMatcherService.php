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
        
        // Extract meaningful words from description (excluding brand name if found)
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
        // ✅ If no brand found, use ALL words from description for matching (no brand removal)
        
        // ✅ NEW: Identify key terms (model numbers, brand identifiers) vs generic words
        // Do this after brand removal so we have the final search words
        $keyTerms = $this->identifyKeyTerms($searchWords);
        
        // STEP 2: Build query - match by brand OR product name/model words
        // Category and sub-category are completely ignored
        $query = Product::query()->with('brand');
        
        $hasBrandMatch = false;
        $hasWordMatch = false;
        
        // Build the WHERE clause: brand_id = X OR (model contains word1 OR word2 OR ...)
        // If brand is found, prioritize brand matches, but also allow word matches
        // If no brand found, search by product name/model words only
        $query->where(function ($q) use ($extractedBrandId, $searchWords, &$hasBrandMatch, &$hasWordMatch) {
            // Option 1: Match by brand (if brand was extracted) - PRIORITY
            if ($extractedBrandId) {
                // Primary: exact brand match
                $q->where('brand_id', $extractedBrandId);
                $hasBrandMatch = true;
            }
            
            // Option 2: Match by product name/model words (if we have search words)
            if (!empty($searchWords)) {
                if ($extractedBrandId) {
                    // Brand found - also search by words (from other brands)
                    $q->orWhere(function ($subQ) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $wordLower = strtolower($word);
                            // Match word anywhere in product model (normalized, case-insensitive)
                            $subQ->orWhereRaw('LOWER(model) LIKE ?', ['%' . $wordLower . '%']);
                        }
                    });
                } else {
                    // ✅ No brand found - search by product name/model words only
                    // Prioritize products that match multiple words (especially key terms)
                    $q->where(function ($subQ) use ($searchWords) {
                        foreach ($searchWords as $word) {
                            $wordLower = strtolower($word);
                            // Match word anywhere in product model (normalized, case-insensitive)
                            $subQ->orWhereRaw('LOWER(model) LIKE ?', ['%' . $wordLower . '%']);
                            // Also try normalized_model for better matching
                            $subQ->orWhereRaw('LOWER(normalized_model) LIKE ?', ['%' . $wordLower . '%']);
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
        // ✅ When no brand found, prioritize products that match the start of description (likely brand/model)
        if ($extractedBrandId) {
            $query->orderByRaw('CASE WHEN brand_id = ? THEN 0 ELSE 1 END', [$extractedBrandId]);
        } elseif (!empty($keyTerms)) {
            // Prioritize products where model starts with key terms (likely same brand/model)
            $firstKeyTerm = strtolower($keyTerms[0]);
            $query->orderByRaw('CASE WHEN LOWER(model) LIKE ? THEN 0 ELSE 1 END', [$firstKeyTerm . '%']);
        }
        
        // Get products that match either brand OR product name/model words
        $products = $query->limit(500)->get();
        
        // STEP 3: Calculate confidence for each product
        $description = $item->original_description;
        $descriptionNormalized = strtolower(preg_replace('/[^a-z0-9\s]/', ' ', $description));
        $descriptionNormalized = preg_replace('/\s+/', ' ', trim($descriptionNormalized));
        $descriptionWords = $this->extractWordsForMatching($description);
        
        // ✅ NEW: Identify key terms (model numbers, brand-like identifiers) vs generic words
        $keyTerms = $this->identifyKeyTerms($descriptionWords);
        $genericWords = array_diff($descriptionWords, $keyTerms);
        
        $matches = $products->map(function ($product) use ($searchWords, $extractedBrandId, $extractedBrandName, $description, $descriptionNormalized, $descriptionWords, $keyTerms, $genericWords) {
            $confidence = 0.0;
            $matchTypes = [];
            $matchCount = 0;
            
            // ✅ CRITICAL: Check for exact or near-exact model match first
            $productModelNormalized = strtolower(preg_replace('/[^a-z0-9\s]/', ' ', $product->model));
            $productModelNormalized = preg_replace('/\s+/', ' ', trim($productModelNormalized));
            
            // Extract words from product model for comparison
            $productModelWords = $this->extractWordsForMatching($product->model);
            
            // Check if description and product model are very similar (exact or near-exact match)
            $descriptionWordsLower = array_map('strtolower', $descriptionWords);
            $productModelWordsLower = array_map('strtolower', $productModelWords);
            
            // Count how many description words appear in product model
            // ✅ Enhanced: treat model codes with trailing letters as matches (e.g. "vl3500" vs "vl3500q")
            $matchingWords = [];
            foreach ($descriptionWordsLower as $dWord) {
                foreach ($productModelWordsLower as $pWord) {
                    if ($dWord === $pWord) {
                        $matchingWords[$dWord] = true;
                        break;
                    }
                    // If either side looks like a model code (contains digits), allow prefix matches
                    if (preg_match('/\d/', $dWord) || preg_match('/\d/', $pWord)) {
                        if (strpos($pWord, $dWord) === 0 || strpos($dWord, $pWord) === 0) {
                            $matchingWords[$dWord] = true;
                            break;
                        }
                    }
                }
            }
            $matchCount = count($matchingWords);
            $totalDescriptionWords = count($descriptionWords);
            
            // ✅ NEW: Count key term matches vs generic word matches
            $keyTermsLower = array_map('strtolower', $keyTerms);
            $genericWordsLower = array_map('strtolower', $genericWords);

            // Enhanced key-term matching: allow prefix matches for model codes
            $matchingKeyTerms = [];
            foreach ($keyTermsLower as $keyTerm) {
                foreach ($productModelWordsLower as $pWord) {
                    if ($keyTerm === $pWord ||
                        (preg_match('/\d/', $keyTerm) && strpos($pWord, $keyTerm) === 0) ||
                        (preg_match('/\d/', $pWord) && strpos($keyTerm, $pWord) === 0)
                    ) {
                        $matchingKeyTerms[$keyTerm] = true;
                        break;
                    }
                }
            }
            $matchingGenericWords = array_intersect($genericWordsLower, $productModelWordsLower);
            $keyTermMatchCount = count($matchingKeyTerms);
            $genericWordMatchCount = count($matchingGenericWords);
            
            // Calculate brand match score (independent)
            $brandScore = 0.0;
            $hasBrandMatch = false;
            if ($extractedBrandId && $product->brand_id === $extractedBrandId) {
                $brandScore = 1.0; // Full score for brand match
                $hasBrandMatch = true;
                $matchTypes[] = 'brand_match';
            }
            
            // ✅ EXACT MATCH DETECTION: If most/all words match, it's likely the same product
            $isExactMatch = false;
            if ($totalDescriptionWords > 0) {
                $matchRatio = $matchCount / $totalDescriptionWords;
                
                // If 80%+ of words match, consider it a near-exact match
                if ($matchRatio >= 0.80 && $matchCount >= 3) {
                    $isExactMatch = true;
                    $matchTypes[] = 'near_exact_match';
                }
                
                // If all words match, it's an exact match
                if ($matchCount === $totalDescriptionWords && $totalDescriptionWords >= 3) {
                    $isExactMatch = true;
                    $matchTypes[] = 'exact_match';
                }
            }
            
            // Calculate product name/model word match score (independent)
            $wordScore = 0.0;
            if ($totalDescriptionWords > 0) {
                // ✅ CRITICAL: Require minimum matches, especially key terms
                // If only 1 generic word matches, heavily penalize (likely false match)
                if ($matchCount === 1 && $keyTermMatchCount === 0 && $genericWordMatchCount === 1) {
                    // Single generic word match - very low confidence (likely false positive)
                    $wordScore = 0.20; // Heavily penalized
                } elseif ($matchCount < 2) {
                    // Less than 2 words match - low confidence
                    $wordScore = 0.30;
                } else {
                    // Base score: percentage of description words that matched
                    $wordScore = $matchCount / $totalDescriptionWords;
                    
                    // ✅ Boost for key term matches (model numbers, brand identifiers)
                    if ($keyTermMatchCount > 0) {
                        $keyTermRatio = $keyTermMatchCount / max(1, count($keyTerms));
                        $wordScore += ($keyTermRatio * 0.40); // Strong boost for key terms
                    }
                    
                    // Boost score based on number of matching words
                    if ($matchCount >= 5) {
                        $wordScore = min(1.0, $wordScore + 0.35); // Very strong match
                    } elseif ($matchCount >= 4) {
                        $wordScore = min(1.0, $wordScore + 0.30); // Strong match
                    } elseif ($matchCount >= 3) {
                        $wordScore = min(1.0, $wordScore + 0.25); // Good match
                    } elseif ($matchCount >= 2) {
                        $wordScore = min(1.0, $wordScore + 0.20); // Decent match
                    }
                    
                    // ✅ Penalize if only generic words match (no key terms)
                    if ($keyTermMatchCount === 0 && $genericWordMatchCount > 0 && $matchCount < 3) {
                        $wordScore *= 0.60; // Reduce confidence if no key terms match
                    }
                }
                
                if ($matchCount > 0) {
                    $matchTypes[] = 'word_match';
                    if ($keyTermMatchCount > 0) {
                        $matchTypes[] = 'key_term_match';
                    }
                }
            }
            
            // Combine scores: prioritize exact matches and brand matches
            if ($isExactMatch && $hasBrandMatch) {
                // Exact match with brand = 100% confidence
                $confidence = 1.0;
            } elseif ($isExactMatch) {
                // Exact match without brand = 95% confidence
                $confidence = 0.95;
            } elseif ($hasBrandMatch) {
                // Brand match = base confidence of 0.70
                $confidence = 0.70;
                
                // Add word match bonus if words also match
                if ($matchCount > 0) {
                    // Strong boost for word matches when brand also matches
                    if ($matchCount >= 4) {
                        $confidence = min(1.0, $confidence + 0.30); // Very strong word match
                    } elseif ($matchCount >= 3) {
                        $confidence = min(1.0, $confidence + 0.25); // Strong word match
                    } elseif ($matchCount >= 2) {
                        $confidence = min(1.0, $confidence + 0.20); // Good word match
                    } elseif ($matchCount >= 1) {
                        $confidence = min(1.0, $confidence + 0.10); // Some word match
                    }
                }
            } else {
                // No brand match - rely on word matching only
                $confidence = $wordScore;
                
                // ✅ Require minimum 2 word matches for word-only matches (reduces false positives)
                if ($matchCount < 2) {
                    // Less than 2 words - very low confidence
                    $confidence = max(0.20, $confidence);
                } elseif ($matchCount >= 2 && $keyTermMatchCount > 0) {
                    // 2+ words with key terms - good confidence
                    $confidence = max(0.60, $confidence);
                } elseif ($matchCount >= 3) {
                    // 3+ words - decent confidence
                    $confidence = max(0.50, $confidence);
                } else {
                    // 2 words but no key terms - moderate confidence
                    $confidence = max(0.40, $confidence);
                }
            }
            
            // Determine primary match type
            $matchType = 'unknown';
            if (in_array('exact_match', $matchTypes)) {
                $matchType = 'exact_match';
            } elseif (in_array('near_exact_match', $matchTypes)) {
                $matchType = 'near_exact_match';
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
        // ✅ Increased threshold to reduce false positives (single generic word matches)
        // Require at least 0.40 confidence (2+ word matches or key term matches)
        $effectiveThreshold = 0.40;
        $matches = $matches->where('confidence', '>=', $effectiveThreshold);
        
        // Sort by confidence (descending)
        // Prioritize exact matches, then brand+word matches, then by confidence
        $matches = $matches->sortByDesc(function ($match) {
            // Highest priority: exact or near-exact matches
            $exactMatchBonus = (in_array($match['match_type'], ['exact_match', 'near_exact_match'])) ? 2.0 : 0.0;
            // High priority: both brand and word match
            $bothMatchBonus = ($match['brand_match'] && $match['word_match']) ? 1.0 : 0.0;
            // Medium priority: brand match only
            $brandMatchBonus = $match['brand_match'] ? 0.5 : 0.0;
            return [$exactMatchBonus, $bothMatchBonus, $brandMatchBonus, $match['confidence']];
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
            
            // Also try original brand name (without normalization) - handles special chars like asterisks
            if (!$matches) {
                // Escape special regex characters but preserve asterisks and other special chars for literal matching
                $escapedBrandName = preg_quote($brandName, '/');
                // Replace escaped asterisks back to literal asterisks (preg_quote escapes them)
                $escapedBrandName = str_replace('\\*', '*', $escapedBrandName);
                
                if (preg_match('/\b' . $escapedBrandName . '\b/i', $normalized, $matchesArray, PREG_OFFSET_CAPTURE)) {
                    $matches = true;
                    $position = $matchesArray[0][1]; // Get position in string
                    $isAtStart = ($position === 0 || 
                                 ($position < 10 && preg_match('/^' . $escapedBrandName . '\b/i', $normalized)));
                }
            }
            
            // ✅ ADDITIONAL: Try matching brand name at the start of description (common pattern)
            // This handles cases like "VARI*LITE" where word boundary might not work perfectly
            if (!$matches) {
                $escapedBrandName = preg_quote($brandName, '/');
                $escapedBrandName = str_replace('\\*', '*', $escapedBrandName);
                
                // Check if description starts with brand name (with optional space/separator)
                if (preg_match('/^' . $escapedBrandName . '[\s\-]/i', $normalized, $matchesArray, PREG_OFFSET_CAPTURE)) {
                    $matches = true;
                    $position = 0;
                    $isAtStart = true;
                }
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
    
    /**
     * Identify key terms (model numbers, brand identifiers) vs generic words
     * Key terms are:
     * - Model numbers (e.g., "VL3500", "DN-360", "MAC350")
     * - Brand-like identifiers (uppercase words, words with special chars)
     * - Words at the start of description (likely brand/model)
     * 
     * Generic words are common descriptive terms that appear in many products
     * 
     * @param array $words Array of words from description
     * @return array Array of key terms
     */
    protected function identifyKeyTerms(array $words): array
    {
        $keyTerms = [];
        $genericWords = ['led', 'wash', 'fixture', 'light', 'profile', 'shuttering', 'moving', 'head', 'speaker', 'cable', 'system', 'unit', 'device'];
        
        foreach ($words as $index => $word) {
            $wordLower = strtolower($word);
            
            // Skip generic words
            if (in_array($wordLower, $genericWords)) {
                continue;
            }
            
            // Model numbers: alphanumeric with optional hyphens/special chars (e.g., "VL3500", "DN-360", "MAC350")
            if (preg_match('/^[A-Z0-9][A-Z0-9\-*]+[0-9]+/i', $word)) {
                $keyTerms[] = $word;
                continue;
            }
            
            // Words with uppercase letters (likely brand/model identifiers)
            if (preg_match('/[A-Z]/', $word) && strlen($word) >= 2) {
                $keyTerms[] = $word;
                continue;
            }
            
            // First 2-3 words are often brand/model identifiers
            if ($index < 3 && strlen($word) >= 3) {
                $keyTerms[] = $word;
            }
        }
        
        return array_unique($keyTerms);
    }
}