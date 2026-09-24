<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\LinearUnit;
use App\Models\PsmProductSubmission;
use App\Models\WeightUnit;
use App\Services\PsmProductSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;

class PsmProductSubmissionController extends Controller
{
    public function __construct(
        private readonly PsmProductSubmissionService $submissionService,
    ) {}

    public function index(): View
    {
        return view('admin.psm-product-submissions.index', [
            'statuses' => [
                PsmProductSubmission::STATUS_PENDING => 'Pending',
                PsmProductSubmission::STATUS_APPROVED => 'Approved',
                PsmProductSubmission::STATUS_REJECTED => 'Rejected',
                'all' => 'All',
            ],
            'pendingCount' => PsmProductSubmission::query()
                ->where('status', PsmProductSubmission::STATUS_PENDING)
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
            $statusFilter = $request->get('status_filter', PsmProductSubmission::STATUS_PENDING);
            $order = $request->get('order', []);
            $orderColumn = (int) data_get($order, '0.column', 5);
            $orderDir = data_get($order, '0.dir', 'desc') === 'asc' ? 'asc' : 'desc';

            $columnMap = [
                0 => 'psm_product_submissions.name',
                1 => 'psm_product_submissions.psm_code',
                2 => 'companies.name',
                3 => 'users.username',
                4 => 'psm_product_submissions.status',
                5 => 'psm_product_submissions.created_at',
            ];
            $orderBy = $columnMap[$orderColumn] ?? 'psm_product_submissions.created_at';

            $query = PsmProductSubmission::withTrashed()
                ->select('psm_product_submissions.*')
                ->leftJoin('companies', 'companies.id', '=', 'psm_product_submissions.company_id')
                ->leftJoin('users', 'users.id', '=', 'psm_product_submissions.submitted_by_user_id')
                ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
                ->with([
                    'company:id,name',
                    'submitter.profile:id,user_id,full_name,first_name,last_name',
                ]);

            if ($statusFilter !== '' && $statusFilter !== 'all') {
                $query->where('psm_product_submissions.status', $statusFilter);
            }

            if ($searchValue !== '') {
                $like = '%'.$searchValue.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('psm_product_submissions.name', 'like', $like)
                        ->orWhere('psm_product_submissions.psm_code', 'like', $like)
                        ->orWhere('companies.name', 'like', $like)
                        ->orWhere('users.username', 'like', $like)
                        ->orWhere('user_profiles.full_name', 'like', $like);
                });
            }

            $totalRecords = PsmProductSubmission::withTrashed()->count();
            $filteredRecords = (clone $query)->count();

            $submissions = $query
                ->orderBy($orderBy, $orderDir)
                ->skip($start)
                ->take($length)
                ->get();

            $data = [];
            foreach ($submissions as $submission) {
                $showUrl = route('admin.psm-product-submissions.show', $submission);
                $submitterName = $submission->submitter?->profile?->full_name
                    ?: ($submission->submitter?->username ?? '—');

                $data[] = [
                    'name' => e($submission->name),
                    'psm_code' => $submission->psm_code ? '<code>'.e($submission->psm_code).'</code>' : '—',
                    'company' => e($submission->company?->name ?? '—'),
                    'submitted_by' => e($submitterName),
                    'status' => '<span class="badge badge-'.$submission->statusBadgeClass().'">'.e($submission->status).'</span>',
                    'created_at' => $submission->created_at?->format('M d, Y H:i') ?? '—',
                    'actions' => '<a href="'.$showUrl.'" class="btn btn-info btn-sm" title="Review"><i class="fas fa-eye"></i></a>',
                ];
            }

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $filteredRecords,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('PSM product submissions DataTables error: '.$e->getMessage());

            return response()->json([
                'draw' => (int) $request->get('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Error loading product submissions.',
            ], 500);
        }
    }

    public function show(PsmProductSubmission $psmProductSubmission): View
    {
        $psmProductSubmission->load([
            'company:id,name',
            'submitter.profile:id,user_id,full_name,first_name,last_name,email',
            'brand:id,name',
            'category:id,name',
            'subCategory:id,name',
            'linearUnit:id,code,name',
            'weightUnit:id,code,name',
            'reviewer.profile:id,user_id,full_name,first_name,last_name',
            'images',
            'inventoryMaster:id,model,psm_code',
        ]);

        return view('admin.psm-product-submissions.show', [
            'submission' => $psmProductSubmission,
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'linearUnits' => LinearUnit::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']),
            'weightUnits' => WeightUnit::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']),
        ]);
    }

    public function approve(Request $request, PsmProductSubmission $psmProductSubmission): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'psm_code' => 'nullable|string|max:255',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'sub_category_id' => 'nullable|integer|exists:sub_categories,id',
            'webpage_url' => 'nullable|url|max:2048',
            'replacement_price' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'linear_unit_id' => 'nullable|integer|exists:linear_units,id',
            'weight_unit_id' => 'nullable|integer|exists:weight_units,id',
            'country_of_origin' => 'nullable|string|max:100',
            'iso_code_2' => 'nullable|string|max:2',
            'iso_code_3' => 'nullable|string|max:3',
            'hsn_code' => 'nullable|string|max:20',
            'admin_notes' => 'nullable|string|max:5000',
        ]);

        try {
            $product = $this->submissionService->approve(
                $psmProductSubmission,
                (int) $request->user()->id,
                $validated,
            );

            return redirect()
                ->route('admin.psm-product-submissions.index')
                ->with('success', 'Submission approved. Product created in PSM inventory (ID '.$product->id.', '.$product->psm_code.').');
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('PSM product submission approval failed', [
                'submission_id' => $psmProductSubmission->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->withInput()->with('error', 'Approval failed: '.$e->getMessage());
        }
    }

    public function reject(Request $request, PsmProductSubmission $psmProductSubmission): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => 'nullable|string|max:5000',
        ]);

        try {
            $this->submissionService->reject(
                $psmProductSubmission,
                (int) $request->user()->id,
                $validated['admin_notes'] ?? null,
            );

            return redirect()
                ->route('admin.psm-product-submissions.index')
                ->with('success', 'Submission rejected. No inventory master record was created.');
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }
}
