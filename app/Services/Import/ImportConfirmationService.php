<?php

namespace App\Services\Import;

use App\Models\ImportSession;
use App\Models\Product;
use App\Models\Equipment;
use App\Services\Import\ProductMatcherService;
use App\Services\Import\TypeMatcherService;
use Illuminate\Support\Facades\DB;

class ImportConfirmationService
{
    public function confirm(ImportSession $session, array $rows): array
    {
        $session = ImportSession::with('items')->where('id', $session->id)->lockForUpdate()->firstOrFail();

        if ($session->status !== ImportSession::STATUS_ACTIVE) {
            throw new \Exception('Import session already finalized.');
        }

        $userId = $session->user_id;
        $companyId = $session->company_id;

        $created = 0;
        $attached = 0;
        $matcher = new ProductMatcherService();
        $typeMatcher = new TypeMatcherService();

        // ✅ Collect row-specific errors instead of throwing immediately
        $errors = [];
        $successfulRows = [];

        foreach ($rows as $rowData) {
            $item = $session->items
                ->where('excel_row_number', $rowData['row'])
                ->first();

            // Skip rows that are already confirmed or explicitly marked as skipped
            if (!$item || $item->status === 'confirmed' || $item->is_skipped) {
                continue;
            }

            try {
                if ($rowData['action'] === 'attach') {
                    // Validate product exists
                    $product = Product::find($rowData['product_id']);
                    if (!$product) {
                        $errors[] = [
                            'row' => $rowData['row'],
                            'message' => "Row {$rowData['row']}: Product not found (ID: {$rowData['product_id']}).",
                            'error_type' => 'product_not_found',
                        ];
                        continue;
                    }

                    // ✅ ADD QUANTITIES: Check if equipment already exists, add quantities instead of replacing
                    $existingEquipment = Equipment::where('user_id', $userId)
                        ->where('company_id', $companyId)
                        ->where('product_id', $rowData['product_id'])
                        ->first();

                    $importQuantity = $item->quantity ?? 0;
                    $importPrice = $item->price;

                    if ($existingEquipment) {
                        // Equipment exists - ADD quantities
                        $updateData = [
                            'quantity' => $existingEquipment->quantity + $importQuantity,
                            // Update software_code if provided and different
                            // 'software_code' => $item->software_code ?? $existingEquipment->software_code,
                        ];

                        // Only override price if a price was actually provided in the import
                        if ($importPrice !== null) {
                            $updateData['price'] = $importPrice;
                        }

                        $existingEquipment->update($updateData);
                    } else {
                        // Equipment doesn't exist - create new
                        Equipment::create([
                            'user_id' => $userId,
                            'company_id' => $companyId,
                            'product_id' => $rowData['product_id'],
                            'quantity' => $importQuantity,
                            'price' => $importPrice,
                            'software_code' => $item->software_code ?? null,
                        ]);
                    }

                    $attached++;
                    $successfulRows[] = $rowData['row'];
                }

                if ($rowData['action'] === 'create') {
                    // ✅ PRE-CREATE VALIDATION: Check for high-confidence matches before creating
                    $matches = $matcher->findMatches($item, 0.85);

                    // If very high confidence match found (90%+), prevent creation
                    $highConfidenceMatch = $matches->where('confidence', '>=', 0.90)->first();
                    if ($highConfidenceMatch) {
                        $confidencePercent = round($highConfidenceMatch['confidence'] * 100);
                        $psmCode = $highConfidenceMatch['psm_code'] ?? 'N/A';
                        $matchType = $highConfidenceMatch['match_type'] ?? 'unknown';

                        $errors[] = [
                            'row' => $rowData['row'],
                            'message' => "Row {$rowData['row']}: High-confidence match found! " .
                                "A similar product already exists (PSM Code: {$psmCode}, Confidence: {$confidencePercent}%, Type: {$matchType}). " .
                                "Please use 'attach' action instead of 'create' to avoid duplicates.",
                            'error_type' => 'duplicate_detected',
                            'match_details' => [
                                'psm_code' => $psmCode,
                                'confidence' => $confidencePercent,
                                'match_type' => $matchType,
                                'product_id' => $highConfidenceMatch['product_id'] ?? null,
                            ],
                        ];
                        continue;
                    }

                    // ✅ TYPE MATCHING: Infer types from matched products or description
                    $matchedProductsArray = $matches->toArray();
                    $types = $typeMatcher->inferTypes($item->original_description, $matchedProductsArray);

                    // Create new product with inferred types
                    $product = Product::create([
                        'model' => $item->original_description,
                        'psm_code' => $this->generateNextPsmCode(),
                        'is_verified' => 0,
                        // 'category_id' => $types['category_id'],
                        // 'brand_id' => $types['brand_id'],
                        // 'sub_category_id' => $types['sub_category_id'],

                    ]);

                    // ✅ ADD QUANTITIES: Check if equipment already exists for newly created product
                    $existingEquipment = Equipment::where('user_id', $userId)
                        ->where('company_id', $companyId)
                        ->where('product_id', $product->id)
                        ->first();

                    $importQuantity = $item->quantity ?? 1;
                    $importPrice = $item->price;

                    if ($existingEquipment) {
                        // Equipment exists - ADD quantities
                        $updateData = [
                            'quantity' => $existingEquipment->quantity + $importQuantity,
                            // 'software_code' => $item->software_code ?? $existingEquipment->software_code,
                        ];

                        // Only override price if a price was actually provided in the import
                        if ($importPrice !== null && !empty($importPrice)) {
                            $updateData['price'] = $importPrice;
                        }

                        $existingEquipment->update($updateData);
                    } else {
                        // Equipment doesn't exist - create new
                        Equipment::create([
                            'user_id' => $userId,
                            'company_id' => $companyId,
                            'product_id' => $product->id,
                            'quantity' => $importQuantity,
                            'price' => $importPrice,
                            'software_code' => $item->software_code ?? null,
                        ]);
                    }

                    $created++;
                    $successfulRows[] = $rowData['row'];
                }

                // Only mark as confirmed if processing succeeded
                $item->update([
                    'action' => $rowData['action'],
                    'status' => 'confirmed',
                ]);

            } catch (\Exception $e) {
                // Catch any other unexpected errors for this row
                $errors[] = [
                    'row' => $rowData['row'],
                    'message' => "Row {$rowData['row']}: " . $e->getMessage(),
                    'error_type' => 'processing_error',
                ];
                continue;
            }
        }

        // ✅ PARTIAL IMPORT SUPPORT: Only mark as confirmed if all items are processed
        $pendingCount = $session->items()
            ->where('status', '!=', 'confirmed')
            ->where('status', '!=', 'rejected')
            ->count();

        if ($pendingCount === 0) {
            // All items processed - mark session as confirmed
            $session->update([
                'status' => ImportSession::STATUS_CONFIRMED,
                'created_products' => $session->created_products + $created,
                'attached_products' => $session->attached_products + $attached,
                'completed_at' => now(),
            ]);
        } else {
            // Partial import - keep session active
            $session->update([
                'created_products' => $session->created_products + $created,
                'attached_products' => $session->attached_products + $attached,
            ]);
        }

        // Return structured response with errors if any
        $response = [
            'session_id' => $session->id,
            'created_products' => $created,
            'attached_products' => $attached,
            'total_processed' => $created + $attached,
            'pending_items' => $pendingCount,
            'session_remains_active' => $pendingCount > 0,
            'successful_rows' => $successfulRows,
        ];

        // ✅ Include errors if any occurred
        if (!empty($errors)) {
            $response['errors'] = $errors;
            $response['failed_rows'] = array_column($errors, 'row');
        }

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | PSM Generation
    |--------------------------------------------------------------------------
    */

    protected function generateNextPsmCode(): string
    {
        $latest = Product::select('psm_code')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $next = 1;

        if ($latest && preg_match('/PSM(\d+)/', $latest->psm_code, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        // Keep 5-digit padding to match existing format
        return 'PSM' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
