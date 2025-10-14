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

        return redirect()->route('products.index')
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

        return redirect()->route('products.index')
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

            return redirect()->route('products.index')
                ->with('success', 'Product deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('products.index')
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

