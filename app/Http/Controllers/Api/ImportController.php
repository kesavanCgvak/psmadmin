<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportSession;
use App\Models\ImportSessionMatch;
use App\Services\Import\ImportAnalyzerService;
use App\Services\Import\ImportConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            DB::beginTransaction();

            $analyzer->stageUpload($session, $request->file('file'));

            // ✅ Explicitly commit the transaction to ensure database is updated
            DB::commit();

            // Reload session to get updated stats
            $session->refresh();

            // ✅ DEBUG: Log session state after upload
            Log::info('Import Upload - Session after commit', [
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'company_id' => $session->company_id,
                'status' => $session->status,
                'total_rows' => $session->total_rows,
                'valid_rows' => $session->valid_rows,
            ]);

            // Get rejected rows with their rejection reasons
            $rejectedItems = $session->items()
                ->where('status', 'rejected')
                ->select('id', 'excel_row_number', 'original_description', 'rejection_reason')
                ->orderBy('excel_row_number')
                ->get()
                ->map(function ($item) {
                    return [
                        'row_number' => $item->excel_row_number,
                        'description' => $item->original_description,
                        'rejection_reason' => $item->rejection_reason,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'File uploaded and staged successfully',
                'data' => [
                    'session_id' => $session->id, // ✅ Include session ID for frontend verification
                    'status' => $session->status, // ✅ Include status for verification
                    'total_rows' => $session->total_rows,
                    'valid_rows' => $session->valid_rows,
                    'rejected_rows' => $session->rejected_rows,
                    'rejected_items' => $rejectedItems,
                ],
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            // Return validation errors in consistent format
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
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
            $summary = $this->addExistingEquipmentToSummary($summary, $session);

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
     * Force re-analysis of an existing import session without re-uploading the file.
     */
    public function reanalyze(
        ImportSession $session,
        ImportAnalyzerService $analyzer
    ): JsonResponse {
        $this->authorize('update', $session);

        try {
            // Preserve removed/skipped rows: do NOT reset is_skipped here.
            $summary = $analyzer->reanalyze($session, false);
            $summary = $this->addExistingEquipmentToSummary($summary, $session);

            return response()->json([
                'success' => true,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Re-analysis failed',
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

            // ✅ DEBUG: Log query parameters
            Log::info('Import Index - Query parameters', [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'status_filter' => ImportSession::STATUS_ACTIVE,
            ]);

            // ✅ Filter by both user_id and company_id for security
            // Only return active sessions (explicitly exclude cancelled and confirmed)
            // ✅ CRITICAL FIX: Force read from write connection to avoid read replica lag
            // In production with read replicas, writes go to master but reads might hit replica
            // before replication completes, causing sessions to appear missing
            // Solution: Use write PDO explicitly - this forces read from write/master connection
            $sessions = ImportSession::where('user_id', $user->id)
                ->where('company_id', $user->company_id)
                ->where('status', ImportSession::STATUS_ACTIVE)
                ->useWritePdo() // ✅ CRITICAL: Force read from write connection (avoids replica lag)
                ->withCount(['items as pending_items' => function ($query) {
                    $query->where('status', '!=', 'confirmed');
                }])
                ->orderBy('created_at', 'desc')
                ->get();

            // ✅ DEBUG: Log query results
            Log::info('Import Index - Query results', [
                'count' => $sessions->count(),
                'sessions' => $sessions->map(function ($s) {
                    return [
                        'id' => $s->id,
                        'status' => $s->status,
                        'user_id' => $s->user_id,
                        'company_id' => $s->company_id,
                    ];
                })->toArray(),
            ]);

            // ✅ DEBUG: Also check raw database query
            $rawSessions = DB::table('import_sessions')
                ->where('user_id', $user->id)
                ->where('company_id', $user->company_id)
                ->where('status', ImportSession::STATUS_ACTIVE)
                ->get();

            Log::info('Import Index - Raw DB query results', [
                'count' => $rawSessions->count(),
                'sessions' => $rawSessions->map(function ($s) {
                    return [
                        'id' => $s->id,
                        'status' => $s->status,
                        'user_id' => $s->user_id,
                        'company_id' => $s->company_id,
                    ];
                })->toArray(),
            ]);

            // ✅ Additional safety: Double-check that all returned sessions are active (defense in depth)
            $sessions = $sessions->filter(function ($session) {
                return $session->status === ImportSession::STATUS_ACTIVE;
            });

            return response()->json([
                'success' => true,
                'data' => $sessions->values()->map(function ($session) {
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

            // ✅ FILTER ITEMS:
            // - Always hide explicitly removed/skipped rows
            // - By default, also hide confirmed rows so user only sees remaining work
            $items = $session->items->filter(function ($item) {
                return !$item->is_skipped;
            });
            if (!$showAll) {
                $items = $items->filter(function ($item) {
                    return $item->status !== 'confirmed';
                });
            }

            // Count items by status for summary (ignore skipped rows for pending count)
            $confirmedCount = $session->items->where('status', 'confirmed')->count();
            $pendingCount = $session->items
                ->where('status', '!=', 'confirmed')
                ->where('status', '!=', 'rejected')
                ->where('is_skipped', '!=', true)
                ->count();

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
                            'is_skipped' => (bool) $item->is_skipped,
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
            // Allow saving either action, skip flag, or both.
            'items.*.action' => 'nullable|in:attach,create,skip',
            'items.*.is_skipped' => 'sometimes|boolean',
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
                    // If the user chose to skip/remove this row, hard-delete it from the import.
                    $shouldRemove = false;
                    if (array_key_exists('action', $itemData) && $itemData['action'] === 'skip') {
                        $shouldRemove = true;
                    }
                    if (array_key_exists('is_skipped', $itemData) && $itemData['is_skipped']) {
                        $shouldRemove = true;
                    }

                    if ($shouldRemove) {
                        // Remove all matches for this row, then delete the row itself.
                        ImportSessionMatch::where('import_session_item_id', $item->id)->delete();
                        $item->delete();
                        continue;
                    }

                    $updates = [];

                    if (array_key_exists('action', $itemData) && $itemData['action'] !== 'skip') {
                        $updates['action'] = $itemData['action'];
                        $updates['selected_product_id'] = $itemData['action'] === 'attach'
                            ? ($itemData['product_id'] ?? null)
                            : null;
                    }

                    if (!empty($updates)) {
                        $item->update($updates);
                    }
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

            // Check if there are still pending items (ignore removed/skipped rows)
            $pendingCount = $session->items()
                ->where('status', '!=', 'confirmed')
                ->where('status', '!=', 'rejected')
                ->where('is_skipped', '!=', true)
                ->count();

            // ✅ Determine if we should return success or partial success
            $hasErrors = !empty($result['errors'] ?? []);
            $hasSuccesses = ($result['total_processed'] ?? 0) > 0;

            if ($hasErrors && !$hasSuccesses) {
                // All rows failed - return error response
                return response()->json([
                    'success' => false,
                    'message' => 'Import failed for all rows',
                    'error_type' => 'batch_failed',
                    'data' => $result,
                ], 422);
            }

            if ($hasErrors && $hasSuccesses) {
                // Partial success - some rows succeeded, some failed
                $message = "Import completed with errors. {$result['total_processed']} rows processed successfully. " . count($result['errors']) . " row(s) failed.";
                if ($pendingCount > 0) {
                    $message .= " {$pendingCount} items remain pending.";
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => array_merge($result, [
                        'pending_items' => $pendingCount,
                        'session_remains_active' => $pendingCount > 0,
                        'partial_success' => true,
                    ]),
                ], 200);
            }

            // All rows succeeded
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

            // Only catch unexpected exceptions (not row-specific errors which are now handled in service)
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Import confirmation failed',
                'error_type' => 'unexpected_error',
            ], 500);
        }
    }

    /**
     * Cancel an import session
     */
    public function cancel(Request $request, ImportSession $session): JsonResponse
    {
        $user = $request->user();
         Log::info('Import Index - Query parameters', [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'status_filter' => ImportSession::STATUS_CANCELLED,
            ]);

        // ✅ Explicit authorization: User can only cancel sessions from their company
        if ($user->company_id !== $session->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: You can only cancel import sessions from your company',
            ], 403);
        }

        $this->authorize('update', $session);

        try {
            DB::beginTransaction();

            $session->update([
                'status' => ImportSession::STATUS_CANCELLED,
            ]);

            // ✅ Explicitly commit the transaction to ensure database is updated
            DB::commit();

            // ✅ Refresh the model to ensure it reflects the database state
            $session->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Import session cancelled',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
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

        // Ignore skipped rows when determining overall stage
        $effectiveItems = $session->items->filter(function ($item) {
            return !$item->is_skipped;
        });

        $totalItems = $effectiveItems->count();
        $analyzedItems = $effectiveItems->where('status', 'analyzed')->count();
        $confirmedItems = $effectiveItems->where('status', 'confirmed')->count();
        $pendingItems = $effectiveItems->where('status', 'pending')->count();
        $rejectedItems = $effectiveItems->where('status', 'rejected')->count();

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
     * Enrich analysis summary items with existing equipment info for each match.
     */
    protected function addExistingEquipmentToSummary(array $summary, ImportSession $session): array
    {
        if (isset($summary['items']) && $summary['items'] instanceof \Illuminate\Support\Collection) {
            $summary['items'] = $summary['items']->map(function ($item) use ($session) {
                if ($item->relationLoaded('matches') && $item->matches) {
                    $item->matches->each(function ($match) use ($session) {
                        if ($match->product_id) {
                            $existingEquipment = \App\Models\Equipment::where('user_id', $session->user_id)
                                ->where('company_id', $session->company_id)
                                ->where('product_id', $match->product_id)
                                ->first();

                            if ($existingEquipment) {
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

        return $summary;
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
