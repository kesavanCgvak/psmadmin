<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ImportSessionItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductMatcherService
{
    /**
     * Product matching with strict priority rules.
     *
     * Priority 1 – Brand Match: Detect brand (e.g. VARI*LITE → VARILITE), fetch ALL products under that brand.
     * Priority 2 – Within-Brand: Rank by model keywords (e.g. VL3500). Partial LIKE match, case-insensitive.
     * Priority 3 – Cross-Brand: Include keyword matches from other brands at lower priority.
     *
     * - Limit applied AFTER brand filtering and relevance scoring.
     * - No-brand: when 2+ search words exist, products must match every word (AND); if none, falls back to OR (legacy).
     * - Category/Sub-Category ignored. Word order, hyphens, extra spaces normalized.
     *
     * @param ImportSessionItem $item
     * @param float $minConfidence When ≥0.80, filters with a higher floor (0.50); otherwise 0.32 for import analyze
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
                    $bw = trim($brandWord);
                    if ($bw === '') {
                        continue;
                    }
                    if ($wordLower === $bw) {
                        // Keep short tokens: "ma" in "Grand MA 2" must not be stripped when brand is "MA LIGHTING"
                        if (strlen($bw) <= 3) {
                            continue;
                        }

                        return false;
                    }
                }

                return true;
            });
            // When brand is one word (VARILITE), remove "vari" and "lite" - they form the brand
            $brandAlphanumeric = preg_replace('/[^a-z0-9]/', '', strtolower($extractedBrandName ?? ''));
            if (in_array($brandAlphanumeric, ['varilite', 'variolite'])) {
                $searchWords = array_values(array_filter($searchWords, fn($w) => !in_array(strtolower($w), ['vari', 'lite'])));
            } else {
                $searchWords = array_values($searchWords);
            }
        }
        // If no brand found, use ALL words from description for matching
        
        // ✅ NEW: Identify key terms (model numbers, brand identifiers) vs generic words
        // Do this after brand removal so we have the final search words
        $keyTerms = $this->identifyKeyTerms($searchWords);
        
        // STEP 2: Fetch products - strict priority: brand first, then cross-brand by keywords
        $hasBrandMatch = false;
        $hasWordMatch = false;
        
        // STEP 2a: When brand is found - fetch ALL products under that brand (no token restriction)
        // STEP 2b: Optionally add cross-brand matches (lower priority)
        // STEP 2c: When no brand - search by keywords only
        $products = collect();

        if ($extractedBrandId) {
            $hasBrandMatch = true;
            // PRIORITY 1: Fetch ALL products from the detected brand (do not filter by words)
            $brandProducts = Product::query()->with('brand')
                ->where('brand_id', $extractedBrandId)
                ->get();
            $products = $products->merge($brandProducts);

            // PRIORITY 3: Add cross-brand products that match keywords (lower priority, limit to avoid dilution)
            if (!empty($searchWords)) {
                $hasWordMatch = true;
                $crossBrandProducts = $this->queryCrossBrandProducts($extractedBrandId, $searchWords);
                // Merge, avoiding duplicates (brand products already included)
                $existingIds = $products->pluck('id')->flip();
                foreach ($crossBrandProducts as $p) {
                    if (!$existingIds->has($p->id)) {
                        $products->push($p);
                    }
                }
            }
        } else {
            // No brand - aggressively remove "vari" and "lite" when description starts with VARI*LITE (common false positive source)
            $descAlphanumeric = preg_replace('/[^a-z0-9]/', '', strtolower($description));
            if (strpos($descAlphanumeric, 'varilite') === 0 || strpos($descAlphanumeric, 'variolite') === 0) {
                $searchWords = array_values(array_filter($searchWords, fn($w) => !in_array(strtolower($w), ['vari', 'lite'])));
                $keyTerms = $this->identifyKeyTerms($searchWords); // Recompute after removal
            }
            // No brand found - search by keywords only
            if (empty($searchWords)) {
                return collect();
            }
            $hasWordMatch = true;
            $products = $this->loadProductsForNoBrandKeywordSearch($searchWords);
        }

        if ($products->isEmpty()) {
            return collect();
        }
        
        // STEP 3: Calculate confidence for each product
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
                    if ($dWord === $pWord || $this->tokensMatchAllowingTypo($dWord, $pWord)) {
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
                    if ($keyTerm === $pWord
                        || $this->tokensMatchAllowingTypo($keyTerm, $pWord)
                        || (preg_match('/\d/', $keyTerm) && strpos($pWord, $keyTerm) === 0)
                        || (preg_match('/\d/', $pWord) && strpos($keyTerm, $pWord) === 0)
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
                // Single key term match (e.g., model code "VL3500") - strong signal, allow through
                if ($matchCount === 1 && $keyTermMatchCount === 1) {
                    $soloKey = array_key_first($matchingKeyTerms);
                    $wordScore = ($soloKey !== null && $this->isLikelyGenericSingleMatchKey($soloKey))
                        ? 0.28
                        : 0.70; // Model codes like VL3500 are highly specific; "test"/"model" are not
                } elseif ($matchCount === 1 && $keyTermMatchCount === 0 && $genericWordMatchCount === 1) {
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

            $confidence = $this->adjustConfidenceForProductLineSemantics($description, $product, $confidence);
            
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
        
        // Analyze (default 0.70): broad suggestions. Stricter callers (≥0.80): fewer weak rows.
        $effectiveThreshold = $minConfidence >= 0.80 ? 0.50 : 0.32;
        $matches = $matches->where('confidence', '>=', $effectiveThreshold);
        
        // Sort STRICTLY by priority (enforced order):
        // 1. Brand products first (never rank cross-brand above brand)
        // 2. Within brand: model keyword matches (e.g. VL3500) ranked higher
        // 3. Cross-brand matches last
        $keyTermsLower = array_map('strtolower', $keyTerms);
        $numericSuffix = $this->extractStandaloneNumericSuffix($searchWords);

        $matches = $matches->sortByDesc(function ($match) use ($extractedBrandId, $products, $keyTermsLower, $numericSuffix, $searchWords, $description) {
            $product = $products->firstWhere('id', $match['product_id']);
            $isBrandProduct = $product && $extractedBrandId && $product->brand_id === $extractedBrandId;
            // Tier 1: Brand products always rank above cross-brand
            $brandTier = $isBrandProduct ? 1000 : 0;
            // Tier 1b: Same numeric suffix as import line (e.g. "Test Product 3" → prefer ... 3 over ... 1/2)
            $suffixTier = 0;
            if ($numericSuffix !== null && $product) {
                $suffixTier = $this->productModelContainsNumericSuffix($product, $numericSuffix) ? 100 : 0;
            }
            // Tier 1c: How many non-digit search tokens appear in the catalog model (family / Grand MA / 2port)
            $overlapTier = 0;
            if ($product) {
                $overlapTier = $this->searchWordOverlapScore($searchWords, $product);
            }
            // Tier 1d: Prefer true grandMA2 hardware when user asked for "Grand MA 2" / "grandma2"
            $grandMa2Tier = 0;
            if ($product && preg_match('/\bgrand\s*ma\s*2\b|\bgrandma2\b|\bma\s*2\b/i', $description)) {
                $ml = strtolower((string) ($product->model ?? ''));
                if (preg_match('/grandma2|grand\s*ma\s*2/i', $ml)) {
                    $grandMa2Tier = 150;
                }
            }
            // Tier 2: Model keyword match (brand+word) > brand-only > word-only
            $matchTier = 0;
            if (in_array($match['match_type'], ['exact_match', 'near_exact_match'])) {
                $matchTier = 3;
            } elseif ($match['brand_match'] && $match['word_match']) {
                $matchTier = 2; // VL3500 variants: brand + model keyword
            } elseif ($match['brand_match']) {
                $matchTier = 1; // Other brand products
            }
            // Tier 3: For brand+word matches, boost products whose model contains key terms (e.g. VL3500)
            $modelHasKeyTerm = 0;
            if ($product && !empty($keyTermsLower)) {
                $modelLower = strtolower($product->model ?? '');
                $normModelLower = strtolower($product->normalized_model ?? '');
                foreach ($keyTermsLower as $kt) {
                    if (strpos($modelLower, $kt) !== false || strpos($normModelLower, $kt) !== false) {
                        $modelHasKeyTerm = 1;
                        break;
                    }
                }
            }
            return [$brandTier, $grandMa2Tier, $suffixTier, $overlapTier, $matchTier, $modelHasKeyTerm, $match['confidence']];
        });
        
        // Limit AFTER ranking: large enough to list grandMA2 + related grandMA3 SKUs for one import line
        $resultLimit = $extractedBrandId ? 150 : 40;
        return $matches->take($resultLimit)->values();
    }

    /**
     * No-brand keyword search: AND on 2+ words, but standalone digits are omitted from the SQL AND
     * so "Test Product 3" still returns "Test Product 1/2" as family candidates; digits drive ranking.
     */
    protected function loadProductsForNoBrandKeywordSearch(array $searchWords): Collection
    {
        return $this->runKeywordAndThenOrFallbackWithBase(
            fn () => Product::query()->with('brand'),
            $searchWords,
            2000,
            500
        );
    }

    /**
     * Cross-brand supplement: same AND-vs-OR strategy with small cap.
     */
    protected function queryCrossBrandProducts(int $excludeBrandId, array $searchWords): Collection
    {
        return $this->runKeywordAndThenOrFallbackWithBase(
            fn () => Product::query()->with('brand')->where('brand_id', '!=', $excludeBrandId),
            $searchWords,
            50,
            50
        );
    }

    /**
     * AND (digit-stripped words first), then strict AND including digits, then OR fallback.
     *
     * @param  callable(): Builder  $baseQuery
     */
    protected function runKeywordAndThenOrFallbackWithBase(callable $baseQuery, array $searchWords, int $andLimit, int $orLimit): Collection
    {
        $andWords = $this->wordsForAndKeywordQuery($searchWords);

        if (count($searchWords) >= 2) {
            if (count($andWords) >= 2) {
                $q = $baseQuery();
                $this->applyWordLikeConditions($q, $andWords, true);
                $found = $q->limit($andLimit)->get();
                if ($found->isNotEmpty()) {
                    return $found;
                }
            }
            $q = $baseQuery();
            $this->applyWordLikeConditions($q, $searchWords, true);
            $found = $q->limit($andLimit)->get();
            if ($found->isNotEmpty()) {
                return $found;
            }
        }

        $q = $baseQuery();
        $this->applyWordLikeConditions($q, $searchWords, false);

        return $q->limit($orLimit)->get();
    }

    /**
     * Words for AND query: drop standalone 1–9 digits so family lines (… 1 / … 2 / … 3) share the same pool.
     */
    protected function wordsForAndKeywordQuery(array $searchWords): array
    {
        return array_values(array_filter($searchWords, function ($w) {
            $w = (string) $w;

            return !(strlen($w) === 1 && ctype_digit($w));
        }));
    }

    protected function extractStandaloneNumericSuffix(array $searchWords): ?string
    {
        foreach ($searchWords as $w) {
            $w = (string) $w;
            if (strlen($w) === 1 && ctype_digit($w)) {
                return $w;
            }
        }

        return null;
    }

    protected function productModelContainsNumericSuffix(Product $product, string $digit): bool
    {
        $model = strtolower((string) ($product->model ?? ''));
        $norm = strtolower((string) ($product->normalized_model ?? ''));

        if (preg_match('/\b' . preg_quote($digit, '/') . '\b/', $model)
            || preg_match('/\b' . preg_quote($digit, '/') . '\b/', $norm)) {
            return true;
        }

        return str_ends_with(rtrim($model), $digit) || str_ends_with(rtrim($norm), $digit);
    }

    /**
     * Apply OR (any word) or AND (every word) LIKE conditions on model / normalized fields.
     */
    protected function applyWordLikeConditions(Builder $query, array $searchWords, bool $requireAllWords): void
    {
        if ($requireAllWords && count($searchWords) >= 2) {
            foreach ($searchWords as $word) {
                $wordLower = strtolower($word);
                $wordLike = '%' . $wordLower . '%';
                $query->where(function ($subQ) use ($wordLike) {
                    $subQ->whereRaw('LOWER(model) LIKE ?', [$wordLike])
                        ->orWhereRaw('LOWER(COALESCE(normalized_model, \'\')) LIKE ?', [$wordLike])
                        ->orWhereRaw('LOWER(COALESCE(normalized_full_name, \'\')) LIKE ?', [$wordLike]);
                });
            }

            return;
        }

        $query->where(function ($subQ) use ($searchWords) {
            foreach ($searchWords as $word) {
                $wordLower = strtolower($word);
                $wordLike = '%' . $wordLower . '%';
                $subQ->orWhereRaw('LOWER(model) LIKE ?', [$wordLike])
                    ->orWhereRaw('LOWER(COALESCE(normalized_model, \'\')) LIKE ?', [$wordLike])
                    ->orWhereRaw('LOWER(COALESCE(normalized_full_name, \'\')) LIKE ?', [$wordLike]);
            }
        });
    }

    /**
     * Words that match many unrelated products when used alone (do not treat like "VL3500").
     */
    protected function isLikelyGenericSingleMatchKey(string $word): bool
    {
        $generic = [
            'test', 'model', 'new', 'pro', 'lite', 'led', 'audio', 'speaker', 'cable', 'power',
            'rack', 'line', 'sound', 'light', 'unit', 'series', 'type', 'digital', 'wireless',
        ];

        return in_array(strtolower($word), $generic, true);
    }

    /**
     * Light typo / alias bridge (produt → product).
     */
    protected function tokensMatchAllowingTypo(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $aliases = [
            'produt' => ['product'],
            'produkt' => ['product'],
        ];
        if (isset($aliases[$a]) && in_array($b, $aliases[$a], true)) {
            return true;
        }
        if (isset($aliases[$b]) && in_array($a, $aliases[$b], true)) {
            return true;
        }

        return false;
    }

    /**
     * Nudge scores so "Test Product …" ranks above "Test Model …" when the line says Product/Produt,
     * and lightly penalize obvious MA2 vs MA3 confusion when the user specified "2".
     */
    protected function adjustConfidenceForProductLineSemantics(string $description, Product $product, float $confidence): float
    {
        $desc = strtolower($description);
        $modelLower = strtolower((string) ($product->model ?? ''));

        $descMentionsProductLine = (bool) preg_match('/\b(produt|product)\b/', $desc);
        $modelHasProduct = str_contains($modelLower, 'product');
        $modelHasModelWord = (bool) preg_match('/\bmodel\b/', $modelLower);

        if ($descMentionsProductLine && $modelHasModelWord && ! $modelHasProduct) {
            $confidence = max(0.35, $confidence - 0.14);
        }
        if ($descMentionsProductLine && $modelHasProduct) {
            $confidence = min(1.0, $confidence + 0.06);
        }

        // Grand MA line: surface all grandMA2 SKUs; keep grandMA3 relatives as lower-ranked alternatives
        if (preg_match('/\bgrand\s*ma\s*2\b|\bgrandma2\b|\bma\s*2\b/i', $description)) {
            if (preg_match('/grandma2|grand\s*ma\s*2/i', $modelLower)) {
                $confidence = min(1.0, $confidence + 0.10);
            } elseif (preg_match('/grandma3|grand\s*ma\s*3/i', $modelLower)
                && ! preg_match('/grandma2|grand\s*ma\s*2/i', $modelLower)) {
                // Soft nudge only — harsh penalty was removing most rows below the API threshold
                $confidence = max(0.45, $confidence - 0.05);
            }
        }

        return round(min(1.0, max(0.0, $confidence)), 2);
    }

    /**
     * How many import search tokens (incl. digits) appear in model fields — boosts Grand MA / 2port style rows.
     */
    protected function searchWordOverlapScore(array $searchWords, Product $product): int
    {
        $hay = strtolower(
            (string) ($product->model ?? '')
            . ' ' . (string) ($product->normalized_model ?? '')
            . ' ' . (string) ($product->normalized_full_name ?? '')
        );
        $score = 0;
        foreach ($searchWords as $w) {
            $w = strtolower((string) $w);
            if ($w === '') {
                continue;
            }
            if (strlen($w) === 1 && ctype_digit($w)) {
                if (str_contains($hay, $w)) {
                    $score += 3;
                }
                continue;
            }
            if (strlen($w) >= 2 && str_contains($hay, $w)) {
                $score += 6;
            }
        }

        return min(100, $score);
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
            
            // Skip very short words (less than 2 chars), except single digits (model suffixes: ... 1 vs ... 2)
            if (strlen($word) < 2) {
                if (strlen($word) === 1 && ctype_digit($word)) {
                    $words[] = strtolower($word);
                }
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
        // Alphanumeric-only version: VARI*LITE → varilite, Vari-Lite → varilite (for exact brand match)
        $descriptionAlphanumeric = preg_replace('/[^a-z0-9]/', '', $normalized);
        // Space-separated version: VARI*LITE → vari lite (for "Vari-Lite" two-word brands)
        $normalizedForMatch = preg_replace('/[\*\-_]+/', ' ', $normalized);
        $normalizedForMatch = preg_replace('/\s+/', ' ', trim($normalizedForMatch));

        // Get all brands
        $brands = \App\Models\Brand::all();
        
        $foundBrands = [];
        
        // Find all matching brands and their positions
        foreach ($brands as $brand) {
            $brandName = strtolower(trim($brand->name));
            // Alphanumeric-only: VARILITE → varilite
            $brandNameAlphanumeric = preg_replace('/[^a-z0-9]/', '', $brandName);
            // With spaces: Vari-Lite → vari lite
            $brandNameNormalized = preg_replace('/[^a-z0-9\s]/', ' ', $brandName);
            $brandNameNormalized = preg_replace('/\s+/', ' ', trim($brandNameNormalized));
            
            $position = null;
            $matches = false;
            $isAtStart = false;
            
            // PRIORITY 1: Alphanumeric match - VARI*LITE and VARILITE both become "varilite"
            if (strlen($brandNameAlphanumeric) >= 2 && strpos($descriptionAlphanumeric, $brandNameAlphanumeric) === 0) {
                $matches = true;
                $position = 0;
                $isAtStart = true;
            }
            
            // PRIORITY 2: Normalized with spaces - "vari lite" in "vari lite vl3500..."
            if (!$matches && strlen($brandNameNormalized) >= 2) {
                $pattern = '/\b' . preg_quote($brandNameNormalized, '/') . '\b/i';
                if (preg_match($pattern, $normalizedForMatch, $matchesArray, PREG_OFFSET_CAPTURE)) {
                    $matches = true;
                    $position = $matchesArray[0][1];
                    $isAtStart = ($position === 0 || $position < 10);
                }
            }
            
            // PRIORITY 3: Original brand name (exact special chars)
            if (!$matches) {
                $escapedBrandName = preg_quote($brandName, '/');
                $escapedBrandName = str_replace('\\*', '*', $escapedBrandName);
                if (preg_match('/\b' . $escapedBrandName . '\b/i', $normalized, $matchesArray, PREG_OFFSET_CAPTURE)) {
                    $matches = true;
                    $position = $matchesArray[0][1];
                    $isAtStart = ($position === 0 || $position < 10);
                }
            }
            
            // PRIORITY 4: Brand at start with optional separator
            if (!$matches && strlen($brandNameNormalized) >= 2) {
                if (preg_match('/^' . preg_quote($brandNameNormalized, '/') . '[\s\-]/i', $normalizedForMatch, $matchesArray, PREG_OFFSET_CAPTURE)) {
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
        // "lite" is a very common product suffix (élite, D-Lite, k-lite, 8-Lite) - treat as generic to avoid false positives
        $genericWords = ['led', 'wash', 'fixture', 'light', 'profile', 'shuttering', 'moving', 'head', 'speaker', 'cable', 'system', 'unit', 'device', 'lite'];
        
        foreach ($words as $index => $word) {
            $wordLower = strtolower($word);
            
            // Skip generic words
            if (in_array($wordLower, $genericWords)) {
                continue;
            }
            
            // Model numbers: contain digits - e.g. "VL3500", "vl3500", "DN-360", "MAC350"
            if (preg_match('/\d/', $word) && strlen($word) >= 2) {
                $keyTerms[] = $word;
                continue;
            }
            
            // Alphanumeric model-like codes (letters + digits)
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