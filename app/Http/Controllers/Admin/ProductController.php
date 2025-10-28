<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Brand;
use App\Services\BulkDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index(Request $request)
    {
        $query = Product::select(['id', 'category_id', 'brand_id', 'sub_category_id', 'model', 'psm_code', 'created_at'])
            ->with([
                'category:id,name',
                'subCategory:id,name',
                'brand:id,name'
            ]);

        // Handle sorting
        $sortBy = $request->get('sort', 'created_at');
        $sortOrder = $request->get('order', 'desc');

        // Validate sort column to prevent SQL injection
        $allowedSorts = ['id', 'model', 'psm_code', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }

        // Validate sort order
        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc';
        }

        $products = $query->orderBy($sortBy, $sortOrder)->paginate(25);

        return view('admin.products.products.index', compact('products'));
    }

    /**
     * Get products data for DataTables (server-side processing)
     */
    public function getProductsData(Request $request)
    {
        try {
            $query = Product::select(['id', 'category_id', 'brand_id', 'sub_category_id', 'model', 'psm_code', 'created_at'])
                ->with([
                    'category:id,name',
                    'subCategory:id,name',
                    'brand:id,name'
                ]);

        // Handle DataTables parameters
        $draw = $request->get('draw');
        $start = $request->get('start', 0);
        $length = $request->get('length', 25);
        $searchValue = $request->get('search')['value'] ?? '';
        $orderColumn = $request->get('order')[0]['column'] ?? 0;
        $orderDir = $request->get('order')[0]['dir'] ?? 'desc';

        // Column mapping for ordering
        $columns = ['id', 'brand_id', 'model', 'category_id', 'sub_category_id', 'psm_code', 'created_at'];
        $orderColumnName = $columns[$orderColumn] ?? 'created_at';

        // Apply search filter
        if (!empty($searchValue)) {
            $query->where(function($q) use ($searchValue) {
                $q->where('model', 'like', "%{$searchValue}%")
                  ->orWhere('psm_code', 'like', "%{$searchValue}%")
                  ->orWhereHas('brand', function($brandQuery) use ($searchValue) {
                      $brandQuery->where('name', 'like', "%{$searchValue}%");
                  })
                  ->orWhereHas('category', function($categoryQuery) use ($searchValue) {
                      $categoryQuery->where('name', 'like', "%{$searchValue}%");
                  })
                  ->orWhereHas('subCategory', function($subCategoryQuery) use ($searchValue) {
                      $subCategoryQuery->where('name', 'like', "%{$searchValue}%");
                  });
            });
        }

        // Get total count before filtering
        $totalRecords = Product::count();

        // Get filtered count
        $filteredRecords = $query->count();

        // Apply ordering and pagination
        $products = $query->orderBy($orderColumnName, $orderDir)
                         ->skip($start)
                         ->take($length)
                         ->get();

        // Prepare data for DataTables
        $data = [];
        foreach ($products as $product) {
            $data[] = [
                'checkbox' => '', // Placeholder for checkbox column (rendered client-side)
                'id' => $product->id,
                'brand' => $product->brand ? $product->brand->name : '—',
                'model' => $product->model,
                'category' => $product->category ? $product->category->name : '—',
                'sub_category' => $product->subCategory ? $product->subCategory->name : '—',
                'psm_code' => $product->psm_code ?? '—',
                'created_at' => $product->created_at ? $product->created_at->format('M d, Y') : '—',
                'actions' => $this->getActionButtons($product)
            ];
        }

            return response()->json([
                'draw' => intval($draw),
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $filteredRecords,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('DataTables Products Error: ' . $e->getMessage());
            return response()->json([
                'draw' => intval($request->get('draw', 1)),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Error loading products data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate action buttons HTML for DataTables
     */
    private function getActionButtons($product)
    {
        $viewUrl = route('admin.products.show', $product);
        $editUrl = route('admin.products.edit', $product);
        $deleteUrl = route('admin.products.destroy', $product);

        return '
            <div class="btn-group">
                <a href="' . $viewUrl . '" class="btn btn-info btn-sm" title="View">
                    <i class="fas fa-eye"></i>
                </a>
                <a href="' . $editUrl . '" class="btn btn-warning btn-sm" title="Edit">
                    <i class="fas fa-edit"></i>
                </a>
                <form action="' . $deleteUrl . '" method="POST" class="d-inline" onsubmit="return confirm(\'Are you sure you want to delete this product?\');">
                    ' . csrf_field() . '
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
        ';
    }

    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        $categories = Cache::remember('categories_list', 3600, function () {
            return Category::select(['id', 'name'])->orderBy('name')->get();
        });

        $subCategories = Cache::remember('subcategories_list', 3600, function () {
            return SubCategory::select(['id', 'name', 'category_id'])->orderBy('name')->get();
        });

        $brands = Cache::remember('brands_list', 3600, function () {
            return Brand::select(['id', 'name'])->orderBy('name')->get();
        });

        // Generate the next PSM code for the form
        $nextPsmCode = $this->generateNextPsmCode();

        return view('admin.products.products.create', compact('categories', 'subCategories', 'brands', 'nextPsmCode'));
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'model' => 'required|string|max:255',
            'psm_code' => 'nullable|string|max:255|unique:products,psm_code',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Check for duplicate products using normalized name comparison
        $productName = trim($request->input('model'));
        $normalizedName = $this->normalizeProductName($productName);
        $brandId = $request->input('brand_id');

        $existingProduct = $this->findSimilarProduct($normalizedName, $brandId);

        if ($existingProduct) {
            return redirect()->back()
                ->withErrors(['model' => 'A product with a similar name already exists: "' . $existingProduct->model . '" (ID: ' . $existingProduct->id . ')'])
                ->withInput()
                ->with('duplicate_product', [
                    'id' => $existingProduct->id,
                    'model' => $existingProduct->model,
                    'psm_code' => $existingProduct->psm_code,
                    'brand' => $existingProduct->brand->name ?? 'N/A',
                    'category' => $existingProduct->category->name ?? 'N/A',
                ]);
        }

        // Generate automatic PSM Code if not provided
        $psmCode = $request->input('psm_code');
        if (empty($psmCode)) {
            $psmCode = $this->generateNextPsmCode();
        }

        $productData = $request->all();
        $productData['psm_code'] = $psmCode;

        Product::create($productData);

        // Clear related caches when a new product is created
        Cache::forget('categories_list');
        Cache::forget('subcategories_list');
        Cache::forget('brands_list');

        return redirect()->route('admin.products.index')
            ->with('success', 'Product created successfully.');
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product)
    {
        $product->load(['category', 'subCategory', 'brand', 'equipments']);
        return view('admin.products.products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified product.
     */
    public function edit(Product $product)
    {
        $categories = Cache::remember('categories_list', 3600, function () {
            return Category::select(['id', 'name'])->orderBy('name')->get();
        });

        $subCategories = SubCategory::select(['id', 'name', 'category_id'])
            ->where('category_id', $product->category_id)
            ->orderBy('name')
            ->get();

        $brands = Cache::remember('brands_list', 3600, function () {
            return Brand::select(['id', 'name'])->orderBy('name')->get();
        });

        return view('admin.products.products.edit', compact('product', 'categories', 'subCategories', 'brands'));
    }

    /**
     * Update the specified product in storage.
     */
    public function update(Request $request, Product $product)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'model' => 'required|string|max:255',
            'psm_code' => 'nullable|string|max:255|unique:products,psm_code,' . $product->id,
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $product->update($request->all());

        // Clear related caches when a product is updated
        Cache::forget('categories_list');
        Cache::forget('subcategories_list');
        Cache::forget('brands_list');

        return redirect()->route('admin.products.index')
            ->with('success', 'Product updated successfully.');
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product)
    {
        // Relation checks before deletion
        if ($product->equipments()->exists()) {
            return redirect()->route('admin.products.index')
                ->with('error', 'Cannot delete — this product is linked to equipment.');
        }
        if ($product->rentalJobProducts()->exists()) {
            return redirect()->route('admin.products.index')
                ->with('error', 'Cannot delete — this product is used in rental jobs.');
        }
        if ($product->supplyJobProducts()->exists()) {
            return redirect()->route('admin.products.index')
                ->with('error', 'Cannot delete — this product is used in supply jobs.');
        }

        try {
            $product->delete();

            // Clear related caches when a product is deleted
            Cache::forget('categories_list');
            Cache::forget('subcategories_list');
            Cache::forget('brands_list');

            return redirect()->route('admin.products.index')
                ->with('success', 'Product deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.products.index')
                ->with('error', 'Cannot delete product. ' . $e->getMessage());
        }
    }

    /**
     * Generate the next PSM Code automatically (always PSM_ format)
     */
    private function generateNextPsmCode()
    {
        // Get the latest PSM code from the database (handle both PSM-XXX and PSM_XXX formats)
        $latestProduct = Product::whereNotNull('psm_code')
            ->where(function($query) {
                $query->where('psm_code', 'like', 'PSM-%')
                      ->orWhere('psm_code', 'like', 'PSM_%');
            })
            ->orderByRaw('CAST(SUBSTRING(psm_code, 5) AS UNSIGNED) DESC')
            ->first();

        if ($latestProduct && $latestProduct->psm_code) {
            // Extract the number from the latest PSM code (handle both formats)
            $latestCode = $latestProduct->psm_code;
            if (preg_match('/PSM[-_](\d+)/', $latestCode, $matches)) {
                $nextNumber = intval($matches[1]) + 1;
                return 'PSM_' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
            }
        }

        // If no existing PSM codes or format doesn't match, start with PSM_00001
        return 'PSM_00001';
    }

    /**
     * Normalize product name for comparison
     * Handles case-insensitive, word-order independent comparison
     */
    protected function normalizeProductName(string $productName): string
    {
        // Convert to lowercase
        $normalized = strtolower($productName);

        // Remove extra spaces and normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));

        // Split into words, sort them, and rejoin
        $words = explode(' ', $normalized);
        sort($words);

        // Remove common words that don't add uniqueness
        $commonWords = ['the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by'];
        $words = array_filter($words, function($word) use ($commonWords) {
            return !in_array($word, $commonWords) && strlen($word) > 1;
        });

        return implode(' ', $words);
    }

    /**
     * Find similar products based on normalized name and brand
     */
    protected function findSimilarProduct(string $normalizedName, ?int $brandId): ?Product
    {
        // Get all products with their relationships
        $query = Product::with(['brand', 'category', 'subCategory']);

        // If brand is specified, prioritize products from the same brand
        if ($brandId) {
            $query->where('brand_id', $brandId);
        }

        $products = $query->get();

        foreach ($products as $product) {
            $existingNormalized = $this->normalizeProductName($product->model);

            // Check for exact match after normalization
            if ($existingNormalized === $normalizedName) {
                return $product;
            }

            // Check for high similarity (fuzzy matching)
            $similarity = $this->calculateSimilarity($normalizedName, $existingNormalized);
            if ($similarity >= 0.85) { // 85% similarity threshold
                return $product;
            }
        }

        return null;
    }

    /**
     * Calculate similarity between two normalized product names
     */
    protected function calculateSimilarity(string $name1, string $name2): float
    {
        // Convert to arrays of words
        $words1 = explode(' ', $name1);
        $words2 = explode(' ', $name2);

        // Calculate Jaccard similarity
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        if (empty($union)) {
            return 0;
        }

        return count($intersection) / count($union);
    }

    /**
     * Bulk delete multiple products.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id'
        ]);

        $products = Product::whereIn('id', $request->product_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($products->all(), [
            function (Product $product) {
                if ($product->equipments()->exists()) {
                    return 'Cannot delete — this product is linked to equipment.';
                }
                if ($product->rentalJobProducts()->exists()) {
                    return 'Cannot delete — this product is used in rental jobs.';
                }
                if ($product->supplyJobProducts()->exists()) {
                    return 'Cannot delete — this product is used in supply jobs.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} product/products.";
        }
        if (!empty($blocked)) {
            $blockedList = array_map(function ($b) {
                return $b['label'] . ' — ' . $b['reason'];
            }, $blocked);
            $messageParts[] = 'Skipped: ' . implode('; ', $blockedList);
        }
        if (!empty($errors)) {
            $messageParts[] = 'Errors: ' . implode('; ', $errors);
        }

        $message = implode(' ', $messageParts) ?: 'No products were deleted.';
        $success = $deletedCount > 0;

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => $success,
                'message' => $message,
                'deleted_count' => $deletedCount,
                'blocked' => $blocked,
                'errors' => $errors
            ]);
        }

        return redirect()->route('admin.products.index')
            ->with($success ? 'success' : 'error', $message);
    }
}

