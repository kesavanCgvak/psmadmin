<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Brand;
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

        return view('admin.products.products.create', compact('categories', 'subCategories', 'brands'));
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'brand_id' => 'required|exists:brands,id',
            'model' => 'required|string|max:255',
            'psm_code' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        Product::create($request->all());

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
            'category_id' => 'required|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'brand_id' => 'required|exists:brands,id',
            'model' => 'required|string|max:255',
            'psm_code' => 'nullable|string|max:255',
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
                ->with('error', 'Cannot delete product. It may have associated equipment or be used in rental jobs.');
        }
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
}

