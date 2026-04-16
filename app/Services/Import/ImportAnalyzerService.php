<?php

namespace App\Services\Import;

use App\Models\ImportSession;
use App\Models\ImportSessionItem;
use App\Models\ImportSessionMatch;
use App\Models\Product;
use App\Services\Import\DescriptionValidator;
use App\Services\Import\ProductMatcherService;
use App\Support\ProductNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportAnalyzerService
{
    const MAX_ROWS = 100;

    /**
     * Stage uploaded Excel into DB with enhanced validation
     * 
     * @throws ValidationException If file exceeds limits or contains no valid rows
     */
    public function stageUpload(ImportSession $session, $file): void
    {
        $sheet = IOFactory::load($file->getRealPath())
            ->getActiveSheet()
            ->toArray();

        // Count data rows (excluding header)
        $dataRowCount = max(0, count($sheet) - 1);

        // ✅ ENFORCE 100 ROW LIMIT BEFORE PROCESSING
        if ($dataRowCount > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => "Maximum " . self::MAX_ROWS . " rows allowed per upload. Your file contains {$dataRowCount} data rows.",
            ]);
        }

        // File size check (20MB max)
        $maxFileSize = 20480; // 20MB in KB
        if ($file->getSize() > $maxFileSize * 1024) {
            throw ValidationException::withMessages([
                'file' => "File size exceeds maximum of {$maxFileSize}KB.",
            ]);
        }

        // ✅ CLEAR EXISTING ITEMS BEFORE UPLOADING NEW FILE
        // Delete pending and analyzed items (keep rejected for reference, or delete all)
        // Option: Delete all items to start fresh
        $session->items()->delete();

        $validator = new DescriptionValidator();
        $validRows = 0;
        $rejectedRows = 0;

        foreach ($sheet as $index => $row) {
            if ($index === 0) {
                continue; // Skip header row
            }

            // Excel format:
            // Column A = quantity
            // Column B = description
            // Column C = software_code (optional)
            // Column D = price (optional)
            $quantity = (int)($row[0] ?? 1);
            $description = trim($row[1] ?? '');
            $softwareCode = trim($row[2] ?? '');
            $priceRaw = $row[3] ?? null;

            $rejectionMessages = [];

            // Normalize and validate price (optional column)
            $price = null;
            if ($priceRaw !== null && $priceRaw !== '') {
                // Allow numeric values, including decimals
                if (!is_numeric($priceRaw)) {
                    $rejectionMessages[] = 'Invalid price: must be a numeric value.';
                } else {
                    $price = (float) $priceRaw;
                    // Disallow negative prices
                    if ($price < 0) {
                        $rejectionMessages[] = 'Invalid price: negative values are not allowed.';
                    }
                }
            }

            // Skip completely empty rows
            if (empty($description)) {
                continue;
            }

            // ✅ ENHANCED VALIDATION - REJECT GIBBERISH
            // BUT: Check for existing brand/product matches before rejecting
            $validationPassed = true;
            $hasPotentialMatches = false;
            
            try {
                $validator->validateDescription($description);
            } catch (ValidationException $e) {
                $validationPassed = false;
                // ✅ If validation fails, check for potential matches before rejecting
                $hasPotentialMatches = $this->hasPotentialMatches($description);
                
                // ✅ If validation fails BUT we found potential matches, allow it through
                // The matching algorithm will handle it during analysis
                if (!$hasPotentialMatches) {
                    $descriptionErrors = $e->errors()['description'] ?? [];
                    if (!empty($descriptionErrors)) {
                        $rejectionMessages = array_merge($rejectionMessages, $descriptionErrors);
                    }

                    if (empty($rejectionMessages)) {
                        $rejectionMessages[] = 'Invalid or meaningless description.';
                    }

                    // No potential matches found - reject it
                    $session->items()->create([
                        'excel_row_number' => $index + 1, // ✅ FIX: Excel row numbering (row 2, 3, 4...)
                        'original_description' => $description,
                        'quantity' => $quantity,
                        'software_code' => $softwareCode ?: null,
                        'status' => 'rejected',
                        'price' => $price,
                        'rejection_reason' => implode('; ', $rejectionMessages),
                    ]);
                    $rejectedRows++;
                    continue;
                }
                // If we reach here, validation failed but has potential matches - continue to create as pending
            }

            // If we have price validation errors but description is otherwise acceptable,
            // reject the row with price-related messages.
            if (!empty($rejectionMessages)) {
                $session->items()->create([
                    'excel_row_number' => $index + 1,
                    'original_description' => $description,
                    'quantity' => $quantity,
                    'software_code' => $softwareCode ?: null,
                    'price' => $price,
                    'status' => 'rejected',
                    'rejection_reason' => implode('; ', $rejectionMessages),
                ]);
                $rejectedRows++;
                continue;
            }

            // Valid row (description + price) - extract model and normalize using ProductNormalizer
            $detectedModel = ProductNormalizer::extractModelCode($description);
            $normalizedCode = $detectedModel ? ProductNormalizer::normalizeCode($detectedModel) : null;
            $normalizedFull = ProductNormalizer::normalizeFullName(null, $description);

            $session->items()->create([
                'excel_row_number' => $index + 1, // ✅ FIX: Excel row numbering (row 2, 3, 4...)
                'original_description' => $description,
                'detected_model' => $detectedModel,
                'normalized_model' => $normalizedCode ?? $normalizedFull, // Use normalized code or full name
                'quantity' => $quantity,
                'price' => $price,
                'software_code' => $softwareCode ?: null,
                'status' => 'pending',
            ]);
            $validRows++;
        }

        // ✅ ENSURE AT LEAST ONE VALID ROW
        if ($validRows === 0) {
            throw ValidationException::withMessages([
                'file' => 'No valid product descriptions found in the uploaded file. Please check that descriptions contain recognizable model numbers and meaningful product information.',
            ]);
        }

        $session->update([
            'total_rows' => $dataRowCount,
            'valid_rows' => $validRows,
            'rejected_rows' => $rejectedRows,
            'status' => ImportSession::STATUS_ACTIVE,
        ]);
    }

    /**
     * Analyze staged rows in an existing session using enhanced matching algorithm
     */
    public function analyze(ImportSession $session): array
    {
        // ✅ FIX: Load relationships upfront to prevent N+1 queries
        $session->load(['items.matches']);

        $matcher = new ProductMatcherService();
        $newlyAnalyzed = 0;
        $rejected = 0;
        $matched = 0;

        foreach ($session->items as $item) {
            // Ignore confirmed/imported and explicitly skipped/removed rows
            if ($item->status === ImportSessionItem::STATUS_CONFIRMED || $item->is_skipped) {
                continue;
            }

            // Count rejected items
            if ($item->status === 'rejected') {
                $rejected++;
                continue;
            }

            // If already analyzed, count it but don't re-analyze
            if ($item->status === 'analyzed') {
                // Count matches for already-analyzed items
                if ($item->matches->isNotEmpty()) {
                    $matched++;
                }
                continue;
            }

            // Process pending items
            if ($item->status === 'pending') {
                // Use enhanced matcher to find matches
                $matches = $matcher->findMatches($item, 0.70);

                // Create match records
                foreach ($matches as $match) {
                    ImportSessionMatch::create([
                        'import_session_item_id' => $item->id,
                        'product_id' => $match['product_id'],
                        'psm_code' => $match['psm_code'],
                        'confidence' => $match['confidence'],
                        'match_type' => $match['match_type'] ?? null,
                    ]);
                }

                // Update item status
                $item->update([
                    'status' => 'analyzed',
                ]);

                if ($matches->isNotEmpty()) {
                    $matched++;
                }

                $newlyAnalyzed++;
            }
        }

        // Reload items with fresh matches
        $session->load(['items.matches.product.brand', 'items.matches.product.category', 'items.matches.product.subCategory']);

        // Work only with active items (not skipped/removed, not confirmed/imported)
        $activeItems = $session->items->filter(function ($item) {
            return !$item->is_skipped && $item->status !== ImportSessionItem::STATUS_CONFIRMED;
        });

        // Count totals using only active items
        $totalRows = $activeItems->count();
        $totalAnalyzed = $activeItems->where('status', ImportSessionItem::STATUS_ANALYZED)->count();
        $totalRejected = $activeItems->where('status', ImportSessionItem::STATUS_REJECTED)->count();
        $totalMatched = $activeItems->filter(function ($item) {
            return $item->matches && $item->matches->isNotEmpty();
        })->count();

        $session->update([
            'total_rows' => $totalRows,
            'valid_rows' => $totalAnalyzed,
            'rejected_rows' => $totalRejected,
        ]);

        return [
            'total_rows' => $totalRows,
            'analyzed' => $totalAnalyzed, // Total analyzed among active rows
            'rejected' => $totalRejected,
            'matched' => $totalMatched, // Active items that have at least one match
            'items' => $activeItems,
        ];
    }

    /**
     * Force re-analysis of an existing import session without re-uploading the file.
     *
     * - Clears existing matches for non-confirmed rows
     * - Re-runs validation and normalization for non-confirmed rows based on stored raw data
     * - Optionally resets is_skipped flags (default: true)
     * - Rebuilds match suggestions using the latest matching rules
     */
    public function reanalyze(ImportSession $session, bool $resetSkipped = true): array
    {
        // Load items with matches for this session
        $session->load(['items.matches']);

        // Collect IDs of items that can be safely re-analyzed
        // Exclude confirmed rows and any rows explicitly marked as skipped/removed.
        $retriableItemIds = $session->items
            ->filter(function ($item) {
                return $item->status !== ImportSessionItem::STATUS_CONFIRMED
                    && !$item->is_skipped;
            })
            ->pluck('id');

        if ($retriableItemIds->isNotEmpty()) {
            // Clear existing matches for non-confirmed items
            ImportSessionMatch::whereIn('import_session_item_id', $retriableItemIds)->delete();
        }

        // Optionally reset skip flags so previously skipped rows are reconsidered
        if ($resetSkipped && $retriableItemIds->isNotEmpty()) {
            ImportSessionItem::whereIn('id', $retriableItemIds)->update(['is_skipped' => false]);
        }

        // Re-run validation and normalization for non-confirmed rows based on stored raw data
        $validator = new DescriptionValidator();

        foreach ($session->items as $item) {
            // Never touch confirmed items – they represent already imported inventory
            // Also never touch explicitly skipped/removed items.
            if ($item->status === ImportSessionItem::STATUS_CONFIRMED || $item->is_skipped) {
                continue;
            }

            $description = trim($item->original_description ?? '');

            // If description is somehow empty, mark as rejected and skip
            if ($description === '') {
                $item->update([
                    'status' => ImportSessionItem::STATUS_REJECTED,
                    'rejection_reason' => 'Invalid or meaningless description.',
                    'detected_model' => null,
                    'normalized_model' => null,
                    'action' => null,
                    'selected_product_id' => null,
                ]);
                continue;
            }

            $rejectionMessages = [];

            // Re-validate price if present (price is already normalized to numeric when stored)
            $price = $item->price;
            if ($price !== null) {
                if (!is_numeric($price)) {
                    $rejectionMessages[] = 'Invalid price: must be a numeric value.';
                } elseif ($price < 0) {
                    $rejectionMessages[] = 'Invalid price: negative values are not allowed.';
                }
            }

            $hasPotentialMatches = false;

            try {
                // Re-run enhanced description validation
                $validator->validateDescription($description);
            } catch (ValidationException $e) {
                // If validation fails, check for potential matches before rejecting
                $hasPotentialMatches = $this->hasPotentialMatches($description);

                // If no potential matches, collect description errors and reject
                if (!$hasPotentialMatches) {
                    $descriptionErrors = $e->errors()['description'] ?? [];
                    if (!empty($descriptionErrors)) {
                        $rejectionMessages = array_merge($rejectionMessages, $descriptionErrors);
                    }

                    if (empty($rejectionMessages)) {
                        $rejectionMessages[] = 'Invalid or meaningless description.';
                    }
                }
            }

            // If we have validation errors and no potential matches, mark as rejected
            if (!empty($rejectionMessages) && !$hasPotentialMatches) {
                $item->update([
                    'status' => ImportSessionItem::STATUS_REJECTED,
                    'rejection_reason' => implode('; ', $rejectionMessages),
                    'detected_model' => null,
                    'normalized_model' => null,
                    'action' => null,
                    'selected_product_id' => null,
                ]);
                continue;
            }

            // Valid row (or invalid description but with potential matches) – refresh normalization
            $detectedModel = ProductNormalizer::extractModelCode($description);
            $normalizedCode = $detectedModel ? ProductNormalizer::normalizeCode($detectedModel) : null;
            $normalizedFull = ProductNormalizer::normalizeFullName(null, $description);

            $item->update([
                'status' => ImportSessionItem::STATUS_PENDING,
                'rejection_reason' => null,
                'detected_model' => $detectedModel,
                'normalized_model' => $normalizedCode ?? $normalizedFull,
                'action' => null,
                'selected_product_id' => null,
            ]);
        }

        // After resetting items, reuse the existing analyze() pipeline to rebuild matches
        return $this->analyze($session->fresh());
    }

    /**
     * Legacy analyze method - creates session from file
     * @deprecated Use stageUpload + analyze workflow instead
     */
    public function analyzeFromFile($file, int $userId, int $companyId): ImportSession
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

        if (count($rows) - 1 > self::MAX_ROWS) {
            throw new \Exception('Maximum 100 rows allowed per upload');
        }

        return DB::transaction(function () use ($rows, $userId, $companyId) {

            $session = ImportSession::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'source' => 'excel',
                'status' => 'active',
                'total_rows' => count($rows) - 1,
            ]);

            foreach ($rows as $index => $row) {

                // Skip header
                if ($index === 1) {
                    continue;
                }

                $description = trim($row['B'] ?? '');

                if (!$this->isValidDescription($description)) {
                    ImportSessionItem::create([
                        'import_session_id' => $session->id,
                        'excel_row_number' => $index,
                        'original_description' => $description,
                        'status' => 'rejected',
                        'rejection_reason' => 'Invalid or meaningless description',
                    ]);
                    continue;
                }

                $detectedModel = $this->extractModelCode($description);
                $normalized = $this->normalize($description);

                $item = ImportSessionItem::create([
                    'import_session_id' => $session->id,
                    'excel_row_number' => $index,
                    'original_description' => $description,
                    'detected_model' => $detectedModel,
                    'normalized_model' => $normalized,
                    'status' => 'analyzed',
                ]);

                $this->findMatches($item);
            }

            return $session->load('items.matches');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Matching Logic
    |--------------------------------------------------------------------------
    */

    protected function findMatches(ImportSessionItem $item): void
    {
        $products = Product::select('id', 'model', 'psm_code')->get();

        foreach ($products as $product) {
            $confidence = $this->similarity(
                $item->normalized_model,
                $this->normalize($product->model)
            );

            if ($confidence >= 0.75) {
                ImportSessionMatch::create([
                    'import_session_item_id' => $item->id,
                    'product_id' => $product->id,
                    'psm_code' => $product->psm_code,
                    'confidence' => $confidence,
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function isValidDescription(string $text): bool
    {
        if (strlen($text) < 5)
            return false;
        if (!preg_match('/[A-Z]{1,}\s?\d+/i', $text))
            return false;
        return true;
    }

    protected function extractModelCode(string $text): ?string
    {
        // Use ProductNormalizer for consistency
        return ProductNormalizer::extractModelCode($text);
    }

    protected function normalize(string $text): string
    {
        // Use ProductNormalizer for consistency
        $normalized = ProductNormalizer::normalizeCode($text);
        return $normalized ?? ProductNormalizer::normalizeDescription($text);
    }

    protected function similarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);
        return round($percent / 100, 4);
    }
    
    /**
     * Check if description has potential matches in existing products
     * Used to avoid rejecting items that might match existing products
     * This is a fast pre-check before validation rejection
     * 
     * @param string $description
     * @return bool True if potential matches found (brand name or model code match)
     */
    protected function hasPotentialMatches(string $description): bool
    {
        $descriptionLower = strtolower(trim($description));
        $words = array_filter(array_map('trim', explode(' ', $descriptionLower)));
        
        if (empty($words)) {
            return false;
        }
        
        // Strategy 1: Check if any words match existing brand names (fast check)
        $potentialBrands = [];
        foreach ($words as $word) {
            // Skip common stop words
            $stopWords = ['for', 'the', 'and', 'with', 'from', 'to', 'of', 'a', 'an', 'in', 'on', 'at', 'by', 'is', 'are', 'was', 'were'];
            if (in_array($word, $stopWords)) {
                continue;
            }
            
            // If word is 3+ chars, check if it matches a brand
            if (strlen($word) >= 3) {
                $potentialBrands[] = $word;
            }
        }
        
        if (!empty($potentialBrands)) {
            $brandMatch = \App\Models\Brand::where(function ($query) use ($potentialBrands) {
                foreach ($potentialBrands as $brand) {
                    $query->orWhereRaw('LOWER(name) = ?', [strtolower($brand)])
                          ->orWhereRaw('LOWER(name) LIKE ?', ['%' . strtolower($brand) . '%']);
                }
            })->exists();
            
            if ($brandMatch) {
                return true; // Found brand match - likely a valid product
            }
        }
        
        // Strategy 2: Check for product model code matches (fast check using indexed normalized_model)
        $modelCode = \App\Support\ProductNormalizer::extractModelCode($description);
        if ($modelCode) {
            $normalizedCode = \App\Support\ProductNormalizer::normalizeCode($modelCode);
            if ($normalizedCode && \App\Support\ProductNormalizer::isValidNormalizedCode($normalizedCode)) {
                $productMatch = \App\Models\Product::where(function ($query) use ($normalizedCode) {
                    $query->where('normalized_model', $normalizedCode)
                          ->orWhere('normalized_model', 'LIKE', '%' . $normalizedCode . '%');
                })->exists();
                
                if ($productMatch) {
                    return true; // Found model code match - likely a valid product
                }
            }
        }
        
        // Strategy 3: Quick check for normalized full name matches (using index)
        $normalizedFull = \App\Support\ProductNormalizer::normalizeFullName(null, $description);
        if ($normalizedFull) {
            $fullNameMatch = \App\Models\Product::where('normalized_full_name', 'LIKE', '%' . $normalizedFull . '%')
                ->limit(1)
                ->exists();
            
            if ($fullNameMatch) {
                return true; // Found normalized full name match
            }
        }
        
        return false;
    }
}
