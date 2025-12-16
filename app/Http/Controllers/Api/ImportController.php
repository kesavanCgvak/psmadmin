<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportSession;
use App\Services\Import\ImportAnalyzerService;
use App\Services\Import\ImportConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ImportController extends Controller
{
     use AuthorizesRequests;

    /**
     * Start a new import session
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // ✅ CHECK PER-USER LIMITS (prevent abuse)
            $recentImports = ImportSession::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            if ($recentImports >= 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'Import limit exceeded. Maximum 10 import sessions per week allowed. Please contact support if you need more.',
                ], 429);
            }

            $session = ImportSession::create([
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'status' => ImportSession::STATUS_ACTIVE,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $session->id,
                    'status' => $session->status,
                    'created_at' => $session->created_at,
                ],
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start import session',
            ], 500);
        }
    }

    /**
     * Upload Excel and stage rows
     */
    public function upload(
        Request $request,
        ImportSession $session,
        ImportAnalyzerService $analyzer
    ): JsonResponse {
        $this->authorize('update', $session);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
        ]);

        try {
            $analyzer->stageUpload($session, $request->file('file'));

            // Reload session to get updated stats
            $session->refresh();

            return response()->json([
                'success' => true,
                'message' => 'File uploaded and staged successfully',
                'data' => [
                    'total_rows' => $session->total_rows,
                    'valid_rows' => $session->valid_rows,
                    'rejected_rows' => $session->rejected_rows,
                ],
            ]);

        } catch (ValidationException $e) {
            // Return validation errors in consistent format
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process uploaded file',
            ], 422);
        }
    }

    /**
     * Analyze staged rows (matching)
     */
    public function analyze(
        ImportSession $session,
        ImportAnalyzerService $analyzer
    ): JsonResponse {
        $this->authorize('update', $session);

        try {
            $summary = $analyzer->analyze($session);
            
            // ✅ Add existing equipment info to matches
            // This helps users see if they already have the product in inventory
            if (isset($summary['items']) && $summary['items'] instanceof \Illuminate\Support\Collection) {
                $summary['items'] = $summary['items']->map(function ($item) use ($session) {
                    // Add existing equipment info for each match
                    if ($item->relationLoaded('matches') && $item->matches) {
                        $item->matches->each(function ($match) use ($session) {
                            if ($match->product_id) {
                                $existingEquipment = \App\Models\Equipment::where('user_id', $session->user_id)
                                    ->where('company_id', $session->company_id)
                                    ->where('product_id', $match->product_id)
                                    ->first();
                                
                                if ($existingEquipment) {
                                    // Add as attribute to the match model
                                    $match->setAttribute('existing_equipment', [
                                        'id' => $existingEquipment->id,
                                        'current_quantity' => $existingEquipment->quantity,
                                        'software_code' => $existingEquipment->software_code,
                                    ]);
                                }
                            }
                        });
                    }
                    
                    return $item;
                });
            }

            return response()->json([
                'success' => true,
                'summary' => $summary,
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Analysis failed',
            ], 500);
        }
    }

    /**
     * List user's active import sessions (draft state)
     * Allows users to see and continue working on their pending imports
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $sessions = ImportSession::where('user_id', $user->id)
                ->where('status', ImportSession::STATUS_ACTIVE)
                ->withCount(['items as pending_items' => function ($query) {
                    $query->where('status', '!=', 'confirmed');
                }])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $sessions->map(function ($session) {
                    // Determine current stage/step
                    $stage = $this->determineSessionStage($session);
                    
                    return [
                        'id' => $session->id,
                        'status' => $session->status,
                        'total_rows' => $session->total_rows,
                        'valid_rows' => $session->valid_rows,
                        'rejected_rows' => $session->rejected_rows,
                        'pending_items' => $session->pending_items,
                        'stage' => $stage['step'], // 1, 2, 3, or 4
                        'stage_name' => $stage['name'], // 'start', 'upload', 'review', 'confirm'
                        'stage_description' => $stage['description'],
                        'created_at' => $session->created_at,
                        'updated_at' => $session->updated_at,
                    ];
                }),
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve import sessions',
            ], 500);
        }
    }

    /**
     * Get a specific import session with all items and matches
     * Used to display the preview grid
     * 
     * Query parameter: ?show_all=false (default) - only show pending/analyzed items
     *                  ?show_all=true - show all items including confirmed
     */
    public function show(Request $request, ImportSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        try {
            $showAll = $request->boolean('show_all', false);
            
            $session->load([
                'items.matches.product.brand',
                'items.matches.product.category',
                'items.matches.product.subCategory',
                'items.selectedProduct',
            ]);

            // Determine current stage/step
            $stage = $this->determineSessionStage($session);
            
            // ✅ FILTER ITEMS: Only show pending/analyzed items by default (hide confirmed)
            // This ensures when user continues import, they only see remaining items
            $items = $session->items;
            if (!$showAll) {
                $items = $items->filter(function ($item) {
                    return $item->status !== 'confirmed';
                });
            }
            
            // Count items by status for summary
            $confirmedCount = $session->items->where('status', 'confirmed')->count();
            $pendingCount = $session->items->where('status', '!=', 'confirmed')->where('status', '!=', 'rejected')->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $session->id,
                    'status' => $session->status,
                    'total_rows' => $session->total_rows,
                    'valid_rows' => $session->valid_rows,
                    'rejected_rows' => $session->rejected_rows,
                    'confirmed_items' => $confirmedCount,
                    'pending_items' => $pendingCount,
                    'stage' => $stage['step'], // 1, 2, 3, or 4
                    'stage_name' => $stage['name'], // 'start', 'upload', 'review', 'confirm'
                    'stage_description' => $stage['description'],
                    'created_at' => $session->created_at,
                    'updated_at' => $session->updated_at,
                    'items' => $items->values()->map(function ($item) use ($session) {
                        return [
                            'id' => $item->id,
                            'excel_row_number' => $item->excel_row_number,
                            'original_description' => $item->original_description,
                            'detected_model' => $item->detected_model,
                            'quantity' => $item->quantity,
                            'software_code' => $item->software_code,
                            'status' => $item->status,
                            'rejection_reason' => $item->rejection_reason,
                            'action' => $item->action,
                            'selected_product_id' => $item->selected_product_id,
                            'matches' => $item->matches->map(function ($match) use ($session) {
                                // ✅ Check if user already has this product in inventory
                                $existingEquipment = null;
                                if ($match->product_id) {
                                    $existingEquipment = \App\Models\Equipment::where('user_id', $session->user_id)
                                        ->where('company_id', $session->company_id)
                                        ->where('product_id', $match->product_id)
                                        ->first();
                                }
                                
                                return [
                                    'id' => $match->id,
                                    'product_id' => $match->product_id,
                                    'psm_code' => $match->psm_code,
                                    'confidence' => $match->confidence,
                                    'match_type' => $match->match_type,
                                    'product' => $match->product ? [
                                        'id' => $match->product->id,
                                        'model' => $match->product->model,
                                        'psm_code' => $match->product->psm_code,
                                        'brand' => $match->product->brand ? [
                                            'id' => $match->product->brand->id,
                                            'name' => $match->product->brand->name,
                                        ] : null,
                                        'category' => $match->product->category ? [
                                            'id' => $match->product->category->id,
                                            'name' => $match->product->category->name,
                                        ] : null,
                                        'sub_category' => $match->product->subCategory ? [
                                            'id' => $match->product->subCategory->id,
                                            'name' => $match->product->subCategory->name,
                                        ] : null,
                                    ] : null,
                                    // ✅ Show existing equipment info in matches
                                    'existing_equipment' => $existingEquipment ? [
                                        'id' => $existingEquipment->id,
                                        'current_quantity' => $existingEquipment->quantity,
                                        'software_code' => $existingEquipment->software_code,
                                        'note' => 'You already have this product. Quantities will be added.',
                                    ] : null,
                                ];
                            }),
                            // ✅ Add existing equipment info if product is selected
                            'existing_equipment' => $item->selected_product_id ? $this->getExistingEquipmentInfo(
                                $session->user_id,
                                $session->company_id,
                                $item->selected_product_id
                            ) : null,
                        ];
                    }),
                ],
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve import session',
            ], 500);
        }
    }

    /**
     * Update item selections (save draft state)
     * Allows users to save their matching decisions without confirming
     */
    public function updateSelections(
        Request $request,
        ImportSession $session
    ): JsonResponse {
        $this->authorize('update', $session);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:import_session_items,id',
            'items.*.action' => 'required|in:attach,create',
            'items.*.product_id' => [
                'nullable',
                'integer',
                'exists:products,id',
                'required_if:items.*.action,attach',
            ],
        ]);

        try {
            DB::beginTransaction();

            foreach ($validated['items'] as $itemData) {
                $item = $session->items()->findOrFail($itemData['id']);

                // Only update if item is not already confirmed
                if ($item->status !== 'confirmed') {
                    $item->update([
                        'action' => $itemData['action'],
                        'selected_product_id' => $itemData['action'] === 'attach' 
                            ? $itemData['product_id'] 
                            : null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Selections saved successfully',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update selections',
            ], 500);
        }
    }

    /**
     * Confirm selections and persist inventory
     * ✅ TRANSACTION SAFE
     * ✅ Supports partial imports (only confirmed rows)
     */
    public function confirm(
        Request $request,
        ImportSession $session,
        ImportConfirmationService $confirmer
    ): JsonResponse {
        $this->authorize('confirm', $session);

        $validated = $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.row' => 'required|integer|min:1',
            'rows.*.action' => 'required|in:attach,create',
            'rows.*.product_id' => [
                'nullable',
                'integer',
                'exists:products,id',
                'required_if:rows.*.action,attach',
            ],
        ]);

        // Check for duplicate row numbers
        $rowNumbers = array_column($validated['rows'], 'row');
        if (count($rowNumbers) !== count(array_unique($rowNumbers))) {
            throw ValidationException::withMessages([
                'rows' => 'Duplicate row numbers are not allowed. Each row can only be processed once.',
            ]);
        }

        DB::beginTransaction();

        try {
            $result = $confirmer->confirm($session, $validated['rows']);

            DB::commit();

            // Check if there are still pending items
            $pendingCount = $session->items()
                ->where('status', '!=', 'confirmed')
                ->where('status', '!=', 'rejected')
                ->count();

            $message = $pendingCount > 0
                ? "Import completed. {$pendingCount} items remain pending. You can continue later."
                : 'Import completed successfully';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => array_merge($result, [
                    'pending_items' => $pendingCount,
                    'session_remains_active' => $pendingCount > 0,
                ]),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            // Check if it's a duplicate prevention error
            if (str_contains($e->getMessage(), 'High-confidence match found')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'error_type' => 'duplicate_detected',
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Import confirmation failed',
            ], 500);
        }
    }

    /**
     * Cancel an import session
     */
    public function cancel(ImportSession $session): JsonResponse
    {
        $this->authorize('update', $session);

        try {
            $session->update([
                'status' => ImportSession::STATUS_CANCELLED,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Import session cancelled',
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel import session',
            ], 500);
        }
    }

    /**
     * Determine the current stage/step of an import session
     * Helps frontend resume from the correct step
     * 
     * @param ImportSession $session
     * @return array ['step' => int, 'name' => string, 'description' => string]
     */
    protected function determineSessionStage(ImportSession $session): array
    {
        // Load items if not already loaded
        if (!$session->relationLoaded('items')) {
            $session->load('items');
        }
        
        $totalItems = $session->items->count();
        $analyzedItems = $session->items->where('status', 'analyzed')->count();
        $confirmedItems = $session->items->where('status', 'confirmed')->count();
        $pendingItems = $session->items->where('status', 'pending')->count();
        $rejectedItems = $session->items->where('status', 'rejected')->count();
        
        // Step 1: Start - No items uploaded yet
        if ($totalItems === 0) {
            return [
                'step' => 1,
                'name' => 'start',
                'description' => 'Ready to upload file',
            ];
        }
        
        // Step 2: Upload - Items uploaded but not analyzed
        // If there are pending items (not analyzed), user needs to analyze
        if ($pendingItems > 0 && $analyzedItems === 0) {
            return [
                'step' => 2,
                'name' => 'upload',
                'description' => 'File uploaded, ready to analyze matches',
            ];
        }
        
        // Step 3: Review - Items analyzed, ready to review matches
        // If there are analyzed items, show review step (even if some have actions saved)
        if ($analyzedItems > 0) {
            // Count analyzed items without actions (need selection)
            $analyzedWithoutActions = $session->items
                ->where('status', 'analyzed')
                ->whereNull('action')
                ->count();
            
            // Count analyzed items with actions (draft saved)
            $analyzedWithActions = $session->items
                ->where('status', 'analyzed')
                ->whereNotNull('action')
                ->count();
            
            // If there are any analyzed items (with or without actions), show review
            // User can see matches and modify saved selections
            if ($analyzedWithoutActions > 0 || $analyzedWithActions > 0) {
                $description = 'Matches found, review and select actions';
                if ($analyzedWithActions > 0) {
                    $description .= ' (draft saved)';
                }
                
                return [
                    'step' => 3,
                    'name' => 'review',
                    'description' => $description,
                ];
            }
        }
        
        // Step 4: Confirm - All analyzed items are confirmed (imported)
        // This stage is reached after successful import
        if ($confirmedItems > 0 && $confirmedItems === $analyzedItems) {
            return [
                'step' => 4,
                'name' => 'confirm',
                'description' => 'Import completed',
            ];
        }
        
        // Default: If we have items but unclear state, assume review step
        return [
            'step' => 3,
            'name' => 'review',
            'description' => 'Review matches and select actions',
        ];
    }

    /**
     * Get existing equipment information for a product
     * Helps user see current quantity before importing
     */
    protected function getExistingEquipmentInfo(int $userId, int $companyId, int $productId): ?array
    {
        $equipment = \App\Models\Equipment::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->first();

        if (!$equipment) {
            return null;
        }

        return [
            'id' => $equipment->id,
            'current_quantity' => $equipment->quantity,
            'software_code' => $equipment->software_code,
            'note' => 'This product already exists in your inventory. Quantities will be added, not replaced.',
        ];
    }
}
