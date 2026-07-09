<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryAi\InventorySpecificationEnrichmentService;
use App\Support\InventoryMasterSpecEnrichment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ProductsMissingSpecificationsController extends Controller
{
    public function __construct(
        private readonly InventorySpecificationEnrichmentService $enrichmentService,
    ) {
    }

    public function index(): View
    {
        $baseQuery = Product::query()
            ->tap(fn ($builder) => InventoryMasterSpecEnrichment::scopeMissingDimensionOrWeight($builder));

        return view('admin.products-missing-specifications.index', [
            'maxSyncEnrich' => (int) config('inventory_ai.max_sync_rerun', 25),
            'totalMissing' => (clone $baseQuery)->count(),
            'missingFieldFilters' => $this->missingFieldFilterOptions(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            $draw = (int) $request->get('draw', 1);
            $start = (int) $request->get('start', 0);
            $length = (int) $request->get('length', 25);
            $searchValue = trim((string) data_get($request->get('search'), 'value', ''));
            $missingFieldFilter = $request->get('missing_field_filter', '');
            $order = $request->get('order', []);
            $orderColumn = (int) data_get($order, '0.column', 1);
            $orderDir = data_get($order, '0.dir', 'asc') === 'desc' ? 'desc' : 'asc';

            $columnMap = [
                1 => 'inventory_master.id',
                2 => 'inventory_master.model',
                3 => 'inventory_master.psm_code',
                4 => 'inventory_master.height',
                5 => 'inventory_master.width',
                6 => 'inventory_master.length',
                7 => 'inventory_master.weight',
            ];
            $orderBy = $columnMap[$orderColumn] ?? 'inventory_master.id';

            $query = Product::query()
                ->select('inventory_master.*')
                ->tap(fn ($builder) => InventoryMasterSpecEnrichment::scopeMissingDimensionOrWeight($builder));

            if ($missingFieldFilter !== '' && $missingFieldFilter !== 'all') {
                $query->where(function ($inner) use ($missingFieldFilter) {
                    $inner->whereNull($missingFieldFilter)->orWhere($missingFieldFilter, '');
                });
            }

            if ($searchValue !== '') {
                $query->where(function ($inner) use ($searchValue) {
                    $inner->where('inventory_master.model', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master.psm_code', 'like', '%' . $searchValue . '%')
                        ->orWhere('inventory_master.id', 'like', '%' . $searchValue . '%');
                });
            }

            $totalRecords = Product::query()
                ->tap(fn ($builder) => InventoryMasterSpecEnrichment::scopeMissingDimensionOrWeight($builder))
                ->count();
            $filteredRecords = (clone $query)->count();

            $products = $query
                ->orderBy($orderBy, $orderDir)
                ->skip($start)
                ->take($length)
                ->get();

            $data = [];
            foreach ($products as $product) {
                $missingFields = InventoryMasterSpecEnrichment::missingDimensionOrWeightFields($product);

                $data[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->model ?: '—',
                    'psm_code' => $product->psm_code ?: '—',
                    'height' => $this->formatSpecValue($product->height),
                    'width' => $this->formatSpecValue($product->width),
                    'length' => $this->formatSpecValue($product->length),
                    'weight' => $this->formatSpecValue($product->weight),
                    'missing_fields' => $missingFields,
                    'missing_status' => $this->formatMissingStatus($missingFields),
                ];
            }

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $filteredRecords,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('Products missing specifications DataTables error: ' . $e->getMessage());

            return response()->json([
                'draw' => (int) $request->get('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Error loading products missing specifications.',
            ], 500);
        }
    }

    public function enrich(Request $request): RedirectResponse
    {
        $maxSyncEnrich = (int) config('inventory_ai.max_sync_rerun', 25);

        $validated = $request->validate([
            'product_ids' => 'required|array|min:1|max:' . $maxSyncEnrich,
            'product_ids.*' => 'integer|exists:inventory_master,id',
            'confirm_enrich' => 'accepted',
        ], [
            'confirm_enrich.accepted' => 'Please confirm you want to run AI enrichment for the selected products.',
            'product_ids.max' => "You can enrich at most {$maxSyncEnrich} products at once.",
        ]);

        $productIds = array_values(array_unique(array_map('intval', $validated['product_ids'])));

        $results = $this->enrichmentService->rerunRejectedProducts($productIds);

        $successCount = count(array_filter($results, fn (array $row) => $row['outcome'] === 'Success'));
        $stillRejectedCount = count(array_filter($results, fn (array $row) => $row['outcome'] === 'Still Rejected'));
        $skippedCount = count(array_filter($results, fn (array $row) => $row['outcome'] === 'Skipped'));
        $errorCount = count(array_filter($results, fn (array $row) => in_array($row['outcome'], ['Error', 'Failed'], true)));

        return redirect()
            ->route('admin.products-missing-specifications.index')
            ->with('success', "AI enrichment complete: {$successCount} succeeded, {$stillRejectedCount} still rejected, {$skippedCount} skipped, {$errorCount} errors.")
            ->with('enrich_results', $results);
    }

    /**
     * @return array<string, string>
     */
    private function missingFieldFilterOptions(): array
    {
        return [
            'all' => 'All missing fields',
            'height' => 'Missing height',
            'width' => 'Missing width',
            'length' => 'Missing length',
            'weight' => 'Missing weight',
        ];
    }

    /**
     * @param  list<string>  $missingFields
     */
    private function formatMissingStatus(array $missingFields): string
    {
        if ($missingFields === []) {
            return '—';
        }

        $labels = array_map(
            fn (string $field) => '<span class="badge badge-warning mr-1">' . e(ucfirst(str_replace('_', ' ', $field))) . '</span>',
            $missingFields,
        );

        return implode(' ', $labels);
    }

    private function formatSpecValue(mixed $value): string
    {
        if (InventoryMasterSpecEnrichment::isFieldEmpty($value)) {
            return '—';
        }

        return (string) $value;
    }
}
