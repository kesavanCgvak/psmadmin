<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMasterAiRejection;
use App\Services\InventoryAi\InventorySpecificationEnrichmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class InventoryAiRejectedProductController extends Controller
{
    public function __construct(
        private readonly InventorySpecificationEnrichmentService $enrichmentService,
    ) {
    }

    public function index(): View
    {
        return view('admin.inventory-ai-rejections.index', [
            'categories' => InventoryMasterAiRejection::categoryLabels(),
            'maxSyncRerun' => (int) config('inventory_ai.max_sync_rerun', 25),
            'totalRejected' => InventoryMasterAiRejection::count(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            $draw = (int) $request->get('draw', 1);
            $start = (int) $request->get('start', 0);
            $length = (int) $request->get('length', 25);
            $searchValue = trim((string) data_get($request->get('search'), 'value', ''));
            $categoryFilter = $request->get('category_filter', '');
            $orderDir = data_get($request->get('order'), '0.dir', 'desc') === 'asc' ? 'asc' : 'desc';

            $query = InventoryMasterAiRejection::query()
                ->select('inventory_master_ai_rejections.*')
                ->leftJoin('inventory_master', 'inventory_master.id', '=', 'inventory_master_ai_rejections.inventory_master_id');

            if ($categoryFilter !== '' && $categoryFilter !== 'all') {
                $query->where('inventory_master_ai_rejections.rejection_category', $categoryFilter);
            }

            if ($searchValue !== '') {
                $query->where(function ($inner) use ($searchValue) {
                    $inner->where('inventory_master_ai_rejections.product_name', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master_ai_rejections.rejection_reason', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master_ai_rejections.inventory_master_id', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master.psm_code', 'like', '%' . $searchValue . '%');
                });
            }

            $totalRecords = InventoryMasterAiRejection::count();
            $filteredRecords = (clone $query)->count();

            $rejections = $query
                ->orderBy('inventory_master_ai_rejections.rejected_at', $orderDir)
                ->skip($start)
                ->take($length)
                ->get();

            $categoryLabels = InventoryMasterAiRejection::categoryLabels();
            $data = [];

            foreach ($rejections as $rejection) {
                $data[] = [
                    'id' => $rejection->id,
                    'product_id' => $rejection->inventory_master_id,
                    'product_name' => $rejection->product_name,
                    'rejection_reason' => $rejection->rejection_reason,
                    'rejection_category' => $rejection->rejection_category,
                    'category_label' => $categoryLabels[$rejection->rejection_category] ?? $rejection->rejection_category,
                    'rejected_at' => $rejection->rejected_at?->format('M d, Y H:i') ?? '—',
                    'status' => 'Rejected',
                    'status_badge' => '<span class="badge badge-danger">Rejected</span>',
                    'batch_run_id' => $rejection->batch_run_id ?? '—',
                ];
            }

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $filteredRecords,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI rejected products DataTables error: ' . $e->getMessage());

            return response()->json([
                'draw' => (int) $request->get('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Error loading rejected products.',
            ], 500);
        }
    }

    public function rerun(Request $request): RedirectResponse
    {
        $maxSyncRerun = (int) config('inventory_ai.max_sync_rerun', 25);

        $validated = $request->validate([
            'product_ids' => 'required|array|min:1|max:' . $maxSyncRerun,
            'product_ids.*' => 'integer|exists:inventory_master_ai_rejections,inventory_master_id',
            'confirm_rerun' => 'accepted',
        ], [
            'confirm_rerun.accepted' => 'Please confirm you want to run synchronous enrichment for the selected products.',
            'product_ids.max' => "You can re-run at most {$maxSyncRerun} products at once.",
        ]);

        $productIds = array_values(array_unique(array_map('intval', $validated['product_ids'])));

        $results = $this->enrichmentService->rerunRejectedProducts($productIds);

        $successCount = count(array_filter($results, fn (array $row) => $row['outcome'] === 'Success'));
        $stillRejectedCount = count(array_filter($results, fn (array $row) => $row['outcome'] === 'Still Rejected'));
        $errorCount = count(array_filter($results, fn (array $row) => in_array($row['outcome'], ['Error', 'Failed'], true)));

        return redirect()
            ->route('admin.ai-rejections.index')
            ->with('success', "Re-run complete: {$successCount} succeeded, {$stillRejectedCount} still rejected, {$errorCount} errors.")
            ->with('rerun_results', $results);
    }
}
