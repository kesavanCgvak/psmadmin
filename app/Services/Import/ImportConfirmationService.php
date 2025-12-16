<?php

namespace App\Services\Import;

use App\Models\ImportSession;
use App\Models\Product;
use App\Models\Equipment;
use App\Services\Import\ProductMatcherService;
use App\Services\Import\TypeMatcherService;

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

        foreach ($rows as $rowData) {
            $item = $session->items
                ->where('excel_row_number', $rowData['row'])
                ->first();

            if (!$item || $item->status === 'confirmed') {
                continue;
            }

            if ($rowData['action'] === 'attach') {
                // Validate product exists
                $product = Product::find($rowData['product_id']);
                if (!$product) {
                    throw new \Exception("Row {$rowData['row']}: Product not found (ID: {$rowData['product_id']}).");
                }

                // ✅ ADD QUANTITIES: Check if equipment already exists, add quantities instead of replacing
                $existingEquipment = Equipment::where('user_id', $userId)
                    ->where('company_id', $companyId)
                    ->where('product_id', $rowData['product_id'])
                    ->first();

                $importQuantity = $item->quantity ?? 1;

                if ($existingEquipment) {
                    // Equipment exists - ADD quantities
                    $existingEquipment->update([
                        'quantity' => $existingEquipment->quantity + $importQuantity,
                        // Update software_code if provided and different
                        'software_code' => $item->software_code ?? $existingEquipment->software_code,
                    ]);
                } else {
                    // Equipment doesn't exist - create new
                    Equipment::create([
                        'user_id' => $userId,
                        'company_id' => $companyId,
                        'product_id' => $rowData['product_id'],
                        'quantity' => $importQuantity,
                        'software_code' => $item->software_code ?? null,
                    ]);
                }

                $attached++;
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

                    throw new \Exception(
                        "Row {$rowData['row']}: High-confidence match found! " .
                        "A similar product already exists (PSM Code: {$psmCode}, Confidence: {$confidencePercent}%, Type: {$matchType}). " .
                        "Please use 'attach' action instead of 'create' to avoid duplicates."
                    );
                }

                // ✅ TYPE MATCHING: Infer types from matched products or description
                $matchedProductsArray = $matches->toArray();
                $types = $typeMatcher->inferTypes($item->original_description, $matchedProductsArray);

                // Create new product with inferred types
                $product = Product::create([
                    'model' => $item->original_description,
                    'psm_code' => $this->generateNextPsmCode(),
                    'is_verified' => 0,
                    'category_id' => $types['category_id'],
                    'brand_id' => $types['brand_id'],
                    'sub_category_id' => $types['sub_category_id'],
                ]);

                // ✅ ADD QUANTITIES: Check if equipment already exists for newly created product
                $existingEquipment = Equipment::where('user_id', $userId)
                    ->where('company_id', $companyId)
                    ->where('product_id', $product->id)
                    ->first();

                $importQuantity = $item->quantity ?? 1;

                if ($existingEquipment) {
                    // Equipment exists - ADD quantities
                    $existingEquipment->update([
                        'quantity' => $existingEquipment->quantity + $importQuantity,
                        'software_code' => $item->software_code ?? $existingEquipment->software_code,
                    ]);
                } else {
                    // Equipment doesn't exist - create new
                    Equipment::create([
                        'user_id' => $userId,
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'quantity' => $importQuantity,
                        'software_code' => $item->software_code ?? null,
                    ]);
                }

                $created++;
            }

            $item->update([
                'action' => $rowData['action'],
                'status' => 'confirmed',
            ]);
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

        // Return structured response
        return [
            'session_id' => $session->id,
            'created_products' => $created,
            'attached_products' => $attached,
            'total_processed' => $created + $attached,
            'pending_items' => $pendingCount,
            'session_remains_active' => $pendingCount > 0,
        ];
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
            $next = ((int)$m[1]) + 1;
        }

        return 'PSM' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
