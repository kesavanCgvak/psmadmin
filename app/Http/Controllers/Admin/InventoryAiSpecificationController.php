<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMasterAiLog;
use App\Models\InventoryMasterAiSpec;
use App\Models\LinearUnit;
use App\Models\WeightUnit;
use App\Services\InventoryAi\InventorySpecificationEnrichmentService;
use App\Support\InventoryAiSpecPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;

class InventoryAiSpecificationController extends Controller
{
    public function __construct(
        private readonly InventorySpecificationEnrichmentService $enrichmentService,
    ) {
    }

    public function index(): View
    {
        return view('admin.inventory-ai-specs.index', [
            'statuses' => $this->statusOptions(),
            'pendingCount' => InventoryMasterAiSpec::query()
                ->where('status', InventoryMasterAiSpec::STATUS_PENDING)
                ->count(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            $draw = (int) $request->get('draw', 1);
            $start = (int) $request->get('start', 0);
            $length = (int) $request->get('length', 25);
            $searchValue = trim((string) data_get($request->get('search'), 'value', ''));
            $statusFilter = $request->get('status_filter', InventoryMasterAiSpec::STATUS_PENDING);
            $order = $request->get('order', []);
            $orderColumn = (int) data_get($order, '0.column', 7);
            $orderDir = data_get($order, '0.dir', 'desc') === 'asc' ? 'asc' : 'desc';

            $columnMap = [
                0 => 'inventory_master_ai_specs.id',
                1 => 'inventory_master.id',
                2 => 'inventory_master.model',
                3 => 'brands.name',
                4 => 'categories.name',
                9 => 'inventory_master_ai_specs.confidence_score',
                11 => 'inventory_master_ai_specs.created_at',
                12 => 'inventory_master_ai_specs.status',
            ];
            $orderBy = $columnMap[$orderColumn] ?? 'inventory_master_ai_specs.confidence_score';

            $query = InventoryMasterAiSpec::query()
                ->select('inventory_master_ai_specs.*')
                ->join('inventory_master', 'inventory_master.id', '=', 'inventory_master_ai_specs.inventory_master_id')
                ->leftJoin('brands', 'brands.id', '=', 'inventory_master.brand_id')
                ->leftJoin('categories', 'categories.id', '=', 'inventory_master.category_id')
                ->with([
                    'product.brand:id,name',
                    'product.category:id,name',
                    'product.linearUnit:id,code',
                    'product.weightUnit:id,code',
                    'linearUnit:id,code',
                    'weightUnit:id,code',
                ]);

            if ($statusFilter !== '' && $statusFilter !== 'all') {
                $query->where('inventory_master_ai_specs.status', $statusFilter);
            }

            if ($searchValue !== '') {
                $query->where(function ($inner) use ($searchValue) {
                    $inner->where('inventory_master.model', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master.psm_code', 'like', '%' . $searchValue . '%')
                        ->orWhere('brands.name', 'like', '%' . $searchValue . '%')
                        ->orWhere('categories.name', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master_ai_specs.id', 'like', '%' . $searchValue . '%');
                });
            }

            $totalRecords = InventoryMasterAiSpec::count();
            $filteredRecords = (clone $query)->count();

            $specs = $query
                ->orderBy($orderBy, $orderDir)
                ->skip($start)
                ->take($length)
                ->get();

            $data = [];
            foreach ($specs as $spec) {
                $product = $spec->product;
                $existing = InventoryAiSpecPresenter::formatSpecsForProduct($product);
                $suggested = InventoryAiSpecPresenter::formatSpecsForStaging($spec);

                $data[] = [
                    'id' => $spec->id,
                    'product_id' => $product?->id,
                    'product_name' => $product?->model ?? '—',
                    'manufacturer' => $product?->brand?->name ?? '—',
                    'category' => $product?->category?->name ?? '—',
                    'existing_dimensions' => $existing['dimensions_display'] ?? '—',
                    'existing_weight' => $existing['weight_display'] ?? '—',
                    'suggested_dimensions' => $suggested['dimensions_display'] ?? '—',
                    'suggested_weight' => $suggested['weight_display'] ?? '—',
                    'confidence_score' => $spec->confidence_score ?? '—',
                    'source_url' => $spec->source_url,
                    'created_at' => $spec->created_at?->format('M d, Y H:i') ?? '—',
                    'status' => $spec->status,
                    'status_badge' => '<span class="badge badge-' . InventoryAiSpecPresenter::statusBadgeClass($spec->status) . '">'
                        . e(str_replace('_', ' ', $spec->status)) . '</span>',
                    'actions' => $this->listingActionButtons($spec),
                ];
            }

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $filteredRecords,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI specification review DataTables error: ' . $e->getMessage());

            return response()->json([
                'draw' => (int) $request->get('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Error loading AI specification reviews.',
            ], 500);
        }
    }

    public function show(InventoryMasterAiSpec $aiSpec): View
    {
        $aiSpec->load([
            'product.brand:id,name',
            'product.category:id,name',
            'product.subCategory:id,name',
            'product.linearUnit:id,code,name',
            'product.weightUnit:id,code,name',
            'linearUnit:id,code,name',
            'weightUnit:id,code,name',
            'reviewer.profile:id,user_id,full_name,first_name,last_name',
        ]);

        return view('admin.inventory-ai-specs.show', [
            'aiSpec' => $aiSpec,
            'existingSpecs' => InventoryAiSpecPresenter::formatSpecsForProduct($aiSpec->product),
            'suggestedSpecs' => InventoryAiSpecPresenter::formatSpecsForStaging($aiSpec),
            'linearUnits' => LinearUnit::where('is_active', true)->orderBy('code')->get(),
            'weightUnits' => WeightUnit::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, InventoryMasterAiSpec $aiSpec): RedirectResponse
    {
        $validated = $request->validate([
            'height' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'linear_unit_id' => 'nullable|exists:linear_units,id',
            'weight_unit_id' => 'nullable|exists:weight_units,id',
            'source_url' => 'nullable|url|max:1024',
        ]);

        try {
            $this->enrichmentService->updatePendingSpec($aiSpec, $validated);

            return redirect()
                ->route('admin.ai-specifications.show', $aiSpec)
                ->with('success', 'Suggested values saved.');
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function approve(Request $request, InventoryMasterAiSpec $aiSpec): RedirectResponse
    {
        $validated = $request->validate([
            'height' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'linear_unit_id' => 'nullable|exists:linear_units,id',
            'weight_unit_id' => 'nullable|exists:weight_units,id',
            'review_notes' => 'nullable|string|max:5000',
        ]);

        try {
            $this->enrichmentService->approvePendingSpec(
                $aiSpec,
                (int) $request->user()->id,
                $validated,
                $validated['review_notes'] ?? null,
            );

            return redirect()
                ->route('admin.ai-specifications.index')
                ->with('success', 'AI specification approved and applied to inventory master.');
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('AI specification approval failed.', [
                'spec_id' => $aiSpec->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Approval failed: ' . $e->getMessage());
        }
    }

    public function reject(Request $request, InventoryMasterAiSpec $aiSpec): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => 'nullable|string|max:5000',
        ]);

        try {
            $this->enrichmentService->rejectPendingSpec(
                $aiSpec,
                (int) $request->user()->id,
                $validated['review_notes'] ?? null,
            );

            return redirect()
                ->route('admin.ai-specifications.index')
                ->with('success', 'AI specification rejected.');
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function auditLogs(): View
    {
        return view('admin.inventory-ai-specs.audit-logs');
    }

    public function auditLogsData(Request $request): JsonResponse
    {
        try {
            $draw = (int) $request->get('draw', 1);
            $start = (int) $request->get('start', 0);
            $length = (int) $request->get('length', 25);
            $searchValue = trim((string) data_get($request->get('search'), 'value', ''));
            $updatedByFilter = $request->get('updated_by_filter', '');
            $orderDir = data_get($request->get('order'), '0.dir', 'desc') === 'asc' ? 'asc' : 'desc';

            $query = InventoryMasterAiLog::query()
                ->select('inventory_master_ai_logs.*')
                ->join('inventory_master', 'inventory_master.id', '=', 'inventory_master_ai_logs.inventory_master_id')
                ->leftJoin('users', 'users.id', '=', 'inventory_master_ai_logs.reviewed_by')
                ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
                ->with([
                    'product:id,model',
                    'reviewer.profile:id,user_id,full_name,first_name,last_name',
                ]);

            if ($updatedByFilter !== '' && in_array($updatedByFilter, ['AI', 'Manual'], true)) {
                $query->where('inventory_master_ai_logs.updated_by', $updatedByFilter);
            }

            if ($searchValue !== '') {
                $query->where(function ($inner) use ($searchValue) {
                    $inner->where('inventory_master.model', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master_ai_logs.field_name', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master_ai_logs.old_value', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master_ai_logs.new_value', 'like', '%' . $searchValue . '%')
                        ->orWhere('user_profiles.full_name', 'like', '%' . $searchValue . '%')
                        ->orWhere('user_profiles.first_name', 'like', '%' . $searchValue . '%')
                        ->orWhere('user_profiles.last_name', 'like', '%' . $searchValue . '%');
                });
            }

            $totalRecords = InventoryMasterAiLog::count();
            $filteredRecords = (clone $query)->count();

            $logs = $query
                ->orderBy('inventory_master_ai_logs.created_at', $orderDir)
                ->skip($start)
                ->take($length)
                ->get();

            $data = [];
            foreach ($logs as $log) {
                $data[] = [
                    'product_name' => $log->product?->model ?? '—',
                    'field_name' => $log->field_name,
                    'old_value' => $log->old_value ?? '—',
                    'new_value' => $log->new_value ?? '—',
                    'confidence_score' => $log->confidence_score ?? '—',
                    'source_url' => $log->source_url,
                    'updated_by' => $log->updated_by,
                    'reviewer_name' => InventoryAiSpecPresenter::reviewerDisplayName($log->reviewer),
                    'created_at' => $log->created_at?->format('M d, Y H:i') ?? '—',
                ];
            }

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $filteredRecords,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI specification audit log DataTables error: ' . $e->getMessage());

            return response()->json([
                'draw' => (int) $request->get('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Error loading audit logs.',
            ], 500);
        }
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            InventoryMasterAiSpec::STATUS_PENDING => 'Pending',
            'all' => 'All statuses',
            InventoryMasterAiSpec::STATUS_APPROVED => 'Approved',
            InventoryMasterAiSpec::STATUS_REJECTED => 'Rejected',
            InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION => 'Insufficient information',
        ];
    }

    private function listingActionButtons(InventoryMasterAiSpec $spec): string
    {
        $showUrl = route('admin.ai-specifications.show', $spec);
        $buttons = '<a href="' . $showUrl . '" class="btn btn-info btn-sm" title="Review"><i class="fas fa-eye"></i></a>';

        if ($spec->status === InventoryMasterAiSpec::STATUS_PENDING) {
            $buttons .= ' <span class="badge badge-warning ml-1">Review</span>';
        }

        return '<div class="btn-group">' . $buttons . '</div>';
    }
}
