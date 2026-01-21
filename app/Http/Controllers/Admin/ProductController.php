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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index(Request $request)
    {
        $query = Product::select(['id', 'category_id', 'brand_id', 'sub_category_id', 'model', 'psm_code', 'is_verified', 'created_at'])
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
            $query = Product::select(['id', 'category_id', 'brand_id', 'sub_category_id', 'model', 'psm_code', 'is_verified', 'created_at'])
                ->with([
                    'category:id,name',
                    'subCategory:id,name',
                    'brand:id,name'
                ]);

            // Handle DataTables parameters
            $draw = $request->get('draw');
            $start = $request->get('start', 0);
            $length = $request->get('length', 25);
            $search = $request->get('search', []);
            $searchValue = is_array($search) && isset($search['value']) ? $search['value'] : '';
            $order = $request->get('order', []);
            $orderColumn = (is_array($order) && isset($order[0]['column'])) ? $order[0]['column'] : 0;
            $orderDir = (is_array($order) && isset($order[0]['dir'])) ? $order[0]['dir'] : 'desc';

            // Column mapping for ordering
            $columns = ['id', 'brand_id', 'model', 'category_id', 'sub_category_id', 'psm_code', 'is_verified', 'created_at'];
            $orderColumnName = $columns[$orderColumn] ?? 'created_at';

            // Apply unverified filter if requested
            if ($request->has('unverified_only') && $request->get('unverified_only') == '1') {
                $query->where('is_verified', 0);
            }

            // Apply search filter
            if (!empty($searchValue)) {
                $query->where(function ($q) use ($searchValue) {
                    $q->where('model', 'like', "%{$searchValue}%")
                        ->orWhere('psm_code', 'like', "%{$searchValue}%")
                        ->orWhereHas('brand', function ($brandQuery) use ($searchValue) {
                            $brandQuery->where('name', 'like', "%{$searchValue}%");
                        })
                        ->orWhereHas('category', function ($categoryQuery) use ($searchValue) {
                            $categoryQuery->where('name', 'like', "%{$searchValue}%");
                        })
                        ->orWhereHas('subCategory', function ($subCategoryQuery) use ($searchValue) {
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
                    'is_verified' => $product->is_verified ?? 0,
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
        $cloneUrl = route('admin.products.clone', $product);
        $deleteUrl = route('admin.products.destroy', $product);

        return '
            <div class="btn-group">
                <a href="' . $viewUrl . '" class="btn btn-info btn-sm" title="View">
                    <i class="fas fa-eye"></i>
                </a>
                <a href="' . $editUrl . '" class="btn btn-warning btn-sm" title="Edit">
                    <i class="fas fa-edit"></i>
                </a>
                <a href="' . $cloneUrl . '" class="btn btn-success btn-sm" title="Clone" onclick="return confirm(\'Are you sure you want to clone this product? A new product will be created with \' (clone)\' appended to the model name.\');">
                    <i class="fas fa-copy"></i>
                </a>
                <button type="button" class="btn btn-secondary btn-sm merge-product-btn" title="Merge/Replace" data-product-id="' . $product->id . '" data-product-name="' . htmlspecialchars($product->model) . '" data-psm-code="' . htmlspecialchars($product->psm_code ?? '') . '">
                    <i class="fas fa-code-branch"></i>
                </button>
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
     * Clone an existing product
     * Creates a new product with all fields copied except PSM Code (generated new)
     * Model name is appended with " (clone)" for easy identification
     */
    public function clone(Product $product)
    {
        try {
            // Create a new product with all fields copied except PSM Code
            $clonedProduct = $product->replicate();

            // Generate new PSM Code (using existing logic)
            $clonedProduct->psm_code = $this->generateNextPsmCode();

            // Append " (clone)" to the model name
            $clonedProduct->model = $product->model . ' (clone)';

            // Set is_verified to 0 for cloned products (they need verification)
            $clonedProduct->is_verified = 0;

            // Clear the ID and timestamps so it creates a new record
            $clonedProduct->id = null;
            $clonedProduct->created_at = null;
            $clonedProduct->updated_at = null;

            // Clear normalized fields - they will be regenerated automatically via model boot
            $clonedProduct->normalized_model = null;
            $clonedProduct->normalized_full_name = null;

            // Save the cloned product (normalization will happen automatically via model boot)
            $clonedProduct->save();

            // Clear related caches when a product is cloned
            Cache::forget('categories_list');
            Cache::forget('subcategories_list');
            Cache::forget('brands_list');

            return redirect()->route('admin.products.edit', $clonedProduct)
                ->with('success', 'Product cloned successfully. You can now edit the cloned product.');

        } catch (\Exception $e) {
            return redirect()->route('admin.products.index')
                ->with('error', 'Failed to clone product: ' . $e->getMessage());
        }
    }

    /**
     * Show the form for creating a new product.
     */
    public function create()
    {
        $categories = Cache::remember('categories_list', 3600, function () {
            return Category::select(['id', 'name'])->orderBy('name')->get();
        });

        // Only load sub-categories for the selected category (if one exists from old input)
        // This prevents showing all sub-categories when a category is already selected
        $selectedCategoryId = old('category_id');
        if ($selectedCategoryId) {
            $subCategories = SubCategory::select(['id', 'name', 'category_id'])
                ->where('category_id', $selectedCategoryId)
                ->orderBy('name')
                ->get();
        } else {
            // If no category selected, pass empty collection
            $subCategories = collect([]);
        }

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
            'webpage_url' => 'nullable|url|max:2048',
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

        // Generate automatic PSM Code
        $psmCode = $this->generateNextPsmCode();

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

        // Use old category_id if available (from validation errors), otherwise use product's category_id
        $categoryId = old('category_id', $product->category_id);
        
        $subCategories = SubCategory::select(['id', 'name', 'category_id'])
            ->where('category_id', $categoryId)
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
            'webpage_url' => 'nullable|url|max:2048',
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
     * Generate the next PSM Code automatically (always PSM format)
     */
    // private function generateNextPsmCode()
    // {
    //     // Get the latest PSM code from the database (handle both PSM-XXX and PSMXXX formats)
    //     $latestProduct = Product::whereNotNull('psm_code')
    //         ->where(function ($query) {
    //             $query->where('psm_code', 'like', 'PSM-%')
    //                 ->orWhere('psm_code', 'like', 'PSM%');
    //         })
    //         ->orderByRaw('CAST(SUBSTRING(psm_code, 5) AS UNSIGNED) DESC')
    //         ->first();

    //     if ($latestProduct && $latestProduct->psm_code) {
    //         // Extract the number from the latest PSM code (handle both formats)
    //         $latestCode = $latestProduct->psm_code;
    //         if (preg_match('/PSM[_](\d+)/', $latestCode, $matches)) {
    //             $nextNumber = intval($matches[1]) + 1;
    //             return 'PSM_' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    //         }
    //     }

    //     // If no existing PSM codes or format doesn't match, start with PSM_00001
    //     return 'PSM_00001';
    // }
    private function generateNextPsmCode(): string
    {
        // Get the highest numeric part from existing PSM codes (legacy + new)
        $latestNumber = Product::whereNotNull('psm_code')
            ->selectRaw("
            MAX(
                CAST(
                    REGEXP_REPLACE(psm_code, '[^0-9]', '')
                AS UNSIGNED)
            ) AS max_number
        ")
            ->value('max_number');

        $nextNumber = ($latestNumber ?? 0) + 1;

        // ✅ NEW FORMAT ONLY (NO UNDERSCORE)
        return 'PSM' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
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
        $words = array_filter($words, function ($word) use ($commonWords) {
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

    /**
     * Get subcategories by category (AJAX endpoint)
     */
    public function getSubCategoriesByCategory($categoryId)
    {
        $subCategories = SubCategory::where('category_id', $categoryId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($subCategories);
    }

    /**
     * Search products for merge modal (AJAX endpoint)
     */
    public function searchProducts(Request $request)
    {
        $search = $request->get('search', '');
        $excludeId = $request->get('exclude_id');

        $query = Product::select(['id', 'model', 'psm_code', 'brand_id', 'category_id'])
            ->with(['brand:id,name', 'category:id,name']);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('model', 'like', "%{$search}%")
                    ->orWhere('psm_code', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('model')->limit(20)->get();

        $results = [];
        foreach ($products as $product) {
            $results[] = [
                'id' => $product->id,
                'model' => $product->model,
                'psm_code' => $product->psm_code ?? '—',
                'brand' => $product->brand ? $product->brand->name : '—',
                'category' => $product->category ? $product->category->name : '—',
            ];
        }

        return response()->json($results);
    }

    /**
     * Merge/Replace product - merge wrong product into correct product
     *
     * Enhanced logic:
     * 1. Check all companies that have inventory records for both Product A and Product B
     * 2. For each company:
     *    - If company has both: Add quantity of Product A to Product B, then delete Product A record
     *    - If company only has Product A: Update product_id to Product B
     * 3. After updating quantities: Delete remaining Product A inventory records
     * 4. Finally: Delete Product A from products table
     */
    public function merge(Request $request, Product $product)
    {
        $validator = Validator::make($request->all(), [
            'correct_product_id' => 'required|exists:products,id|different:id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid product selected.',
                'errors' => $validator->errors()
            ], 422);
        }

        $wrongProductId = $product->id; // Product A (duplicate)
        $correctProductId = $request->input('correct_product_id'); // Product B (correct)

        if ($wrongProductId == $correctProductId) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot merge a product with itself.'
            ], 422);
        }

        $correctProduct = Product::findOrFail($correctProductId);

        try {
            DB::beginTransaction();

            // Step 1: Handle equipments (inventory) table with quantity merging logic
            $this->mergeEquipmentInventory($wrongProductId, $correctProductId);

            // Step 2: Update other tables that reference product_id (no quantity merging needed)
            // Update supply_job_products table
            DB::table('supply_job_products')
                ->where('product_id', $wrongProductId)
                ->update(['product_id' => $correctProductId]);

            // Update rental_job_products table
            DB::table('rental_job_products')
                ->where('product_id', $wrongProductId)
                ->update(['product_id' => $correctProductId]);

            // Check if there are any other tables with product_id references
            // Update job_items table if it exists
            if (Schema::hasTable('job_items')) {
                DB::table('job_items')
                    ->where('product_id', $wrongProductId)
                    ->update(['product_id' => $correctProductId]);
            }

            // Step 3: Delete the wrong product from products table
            $product->delete();

            DB::commit();

            // Clear related caches
            Cache::forget('categories_list');
            Cache::forget('subcategories_list');
            Cache::forget('brands_list');

            return response()->json([
                'success' => true,
                'message' => 'Product merged successfully. All references have been updated.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Product merge error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error merging products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Merge equipment inventory records by company
     *
     * Logic:
     * - For companies with both Product A and Product B:
     *   * Sum all Product A quantities for that company
     *   * Add to the first Product B record for that company
     *   * Delete all Product A records
     * - For companies with only Product A: Update product_id to Product B
     * - Delete any remaining Product A records (safety check)
     */
    private function mergeEquipmentInventory(int $wrongProductId, int $correctProductId)
    {
        // Get all equipment records for the wrong product (Product A), grouped by company
        $wrongProductEquipments = DB::table('equipments')
            ->where('product_id', $wrongProductId)
            ->get()
            ->groupBy('company_id');

        // Get all companies that have equipment for the correct product (Product B)
        $companiesWithCorrectProduct = DB::table('equipments')
            ->where('product_id', $correctProductId)
            ->select('company_id')
            ->distinct()
            ->pluck('company_id')
            ->toArray();

        // Process each company's Product A equipment records
        foreach ($wrongProductEquipments as $companyId => $equipmentRecords) {
            // Check if this company already has equipment for the correct product
            if (in_array($companyId, $companiesWithCorrectProduct)) {
                // Company has both Product A and Product B
                // Sum all Product A quantities for this company
                $totalQuantityToMerge = $equipmentRecords->sum(function ($record) {
                    return $record->quantity ?? 0;
                });

                if ($totalQuantityToMerge > 0) {
                    // Get the first Product B equipment record for this company
                    $firstCorrectEquipment = DB::table('equipments')
                        ->where('product_id', $correctProductId)
                        ->where('company_id', $companyId)
                        ->orderBy('id')
                        ->first();

                    if ($firstCorrectEquipment) {
                        // Add the merged quantity to the first Product B record
                        DB::table('equipments')
                            ->where('id', $firstCorrectEquipment->id)
                            ->increment('quantity', $totalQuantityToMerge);
                    }
                }

                // Delete all Product A equipment records for this company
                $equipmentIds = $equipmentRecords->pluck('id')->toArray();
                DB::table('equipments')
                    ->whereIn('id', $equipmentIds)
                    ->delete();
            } else {
                // Company only has Product A
                // Update all Product A records' product_id to Product B
                $equipmentIds = $equipmentRecords->pluck('id')->toArray();
                DB::table('equipments')
                    ->whereIn('id', $equipmentIds)
                    ->update(['product_id' => $correctProductId]);
            }
        }

        // Final safety check: Delete any remaining Product A equipment records
        // This handles edge cases where records might not have been processed above
        DB::table('equipments')
            ->where('product_id', $wrongProductId)
            ->delete();
    }

    /**
     * Bulk verify products
     */
    public function bulkVerify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid product IDs provided.',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $productIds = $request->input('product_ids');
            $updated = Product::whereIn('id', $productIds)
                ->update(['is_verified' => 1]);

            // Clear related caches
            Cache::forget('categories_list');
            Cache::forget('subcategories_list');
            Cache::forget('brands_list');

            return response()->json([
                'success' => true,
                'message' => "Successfully verified {$updated} product/products.",
                'updated_count' => $updated
            ]);
        } catch (\Exception $e) {
            \Log::error('Bulk verify error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error verifying products: ' . $e->getMessage()
            ], 500);
        }
    }
}

