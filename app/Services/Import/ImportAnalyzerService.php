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

            // Excel format: Column A = quantity, Column B = description, Column C = software_code
            $quantity = (int)($row[0] ?? 1);
            $description = trim($row[1] ?? '');
            $softwareCode = trim($row[2] ?? '');

            // Skip completely empty rows
            if (empty($description)) {
                continue;
            }

            // ✅ ENHANCED VALIDATION - REJECT GIBBERISH
            try {
                $validator->validateDescription($description);
            } catch (ValidationException $e) {
                $session->items()->create([
                    'excel_row_number' => $index + 1, // ✅ FIX: Excel row numbering (row 2, 3, 4...)
                    'original_description' => $description,
                    'quantity' => $quantity,
                    'software_code' => $softwareCode ?: null,
                    'status' => 'rejected',
                    'rejection_reason' => implode('; ', $e->errors()['description'] ?? []),
                ]);
                $rejectedRows++;
                continue;
            }

            // Valid row - extract model and normalize using ProductNormalizer
            $detectedModel = ProductNormalizer::extractModelCode($description);
            $normalizedCode = $detectedModel ? ProductNormalizer::normalizeCode($detectedModel) : null;
            $normalizedFull = ProductNormalizer::normalizeFullName(null, $description);

            $session->items()->create([
                'excel_row_number' => $index + 1, // ✅ FIX: Excel row numbering (row 2, 3, 4...)
                'original_description' => $description,
                'detected_model' => $detectedModel,
                'normalized_model' => $normalizedCode ?? $normalizedFull, // Use normalized code or full name
                'quantity' => $quantity,
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

        // Count total analyzed items (both newly analyzed and previously analyzed)
        $totalAnalyzed = $session->items()->where('status', 'analyzed')->count();
        $totalRejected = $session->items()->where('status', 'rejected')->count();
        $totalMatched = $session->items()->whereHas('matches')->count();

        $session->update([
            'total_rows' => $session->items()->count(),
            'valid_rows' => $totalAnalyzed,
            'rejected_rows' => $totalRejected,
        ]);

        // ✅ Reload to get fresh matches with product relationships
        $session->load(['items.matches.product.brand', 'items.matches.product.category', 'items.matches.product.subCategory']);

        return [
            'total_rows' => $session->items()->count(),
            'analyzed' => $totalAnalyzed, // Total analyzed (new + existing)
            'rejected' => $totalRejected,
            'matched' => $totalMatched, // Items that have at least one match
            'items' => $session->items,
        ];
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
}
