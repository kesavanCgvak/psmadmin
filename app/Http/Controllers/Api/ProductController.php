<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Equipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use App\Models\SavedProduct;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{

    public function search(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2'
        ]);

        $keywords = explode(' ', $request->query('search')); // split into words

        $products = DB::table('products as p')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('sub_categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->select(
                'p.id as product_id',
                DB::raw("CONCAT(b.name, ' ', p.model) as product_name"),
                'p.model as model_name',
                'p.psm_code',
                'b.id as brand_id',
                'b.name as brand_name',
                'c.id as category_id',
                'c.name as category_name',
                'sc.id as sub_category_id',
                'sc.name as sub_category_name'
            )
            ->where(function ($query) use ($keywords) {
                foreach ($keywords as $word) {
                    $like = "%$word%";
                    $query->where(function ($q) use ($like) {
                        $q->where('p.model', 'LIKE', $like)
                            ->orWhere('b.name', 'LIKE', $like)
                            ->orWhere('c.name', 'LIKE', $like)
                            ->orWhere('sc.name', 'LIKE', $like)
                            ->orWhere(DB::raw("CONCAT(b.name, ' ', p.model)"), 'LIKE', $like);
                    });
                }
            })
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    public function searchOld(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2'
        ]);

        $search = '%' . $request->query('search') . '%';

        $products = DB::table('products as p')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('sub_categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->select(
                'p.id as product_id',
                DB::raw("CONCAT(b.name, ' ', p.model) as product_name"), // 👈 concat brand + model
                'p.model as model_name',
                'p.psm_code',
                'b.id as brand_id',
                'b.name as brand_name',
                'c.id as category_id',
                'c.name as category_name',
                'sc.id as sub_category_id',
                'sc.name as sub_category_name'
            )
            // ->where(function ($query) use ($search) {
            //     $query->Where('p.model', 'LIKE', $search)
            //         ->orWhere('b.name', 'LIKE', $search)
            //         ->orWhere('c.name', 'LIKE', $search)
            //         ->orWhere('sc.name', 'LIKE', $search);
            // })
            ->where(function ($query) use ($search) {
                $query->where('p.model', 'LIKE', $search)
                    ->orWhere('b.name', 'LIKE', $search)
                    ->orWhere('c.name', 'LIKE', $search)
                    ->orWhere('sc.name', 'LIKE', $search)
                    ->orWhere(DB::raw("CONCAT(b.name, ' ', p.model)"), 'LIKE', $search);
            })
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    public function createOrAttach(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:sub_categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],

            'category.is_new' => ['nullable', 'boolean'],
            'category.name' => ['required_if:category.is_new,true', 'string', 'max:255'],

            'sub_category.is_new' => ['nullable', 'boolean'],
            'sub_category.name' => ['required_if:sub_category.is_new,true', 'string', 'max:255'],

            'brand.is_new' => ['nullable', 'boolean'],
            'brand.name' => ['required_if:brand.is_new,true', 'string', 'max:255'],

            'name' => ['required', 'string', 'max:255'],
            'psm_code' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'rental_software_code' => ['required', 'string', 'max:255'],
        ]);

        DB::beginTransaction();
        try {
            // 1. Handle Category
            $categoryId = $validated['category_id'] ?? null;
            if (isset($validated['category']['is_new']) && $validated['category']['is_new']) {
                $category = Category::create(['name' => $validated['category']['name']]);
                $categoryId = $category->id;
            }

            // 2. Handle SubCategory
            $subCategoryId = $validated['sub_category_id'] ?? null;
            if (isset($validated['sub_category']['is_new']) && $validated['sub_category']['is_new']) {
                $subCategory = SubCategory::create([
                    'name' => $validated['sub_category']['name'],
                    'category_id' => $categoryId,
                ]);
                $subCategoryId = $subCategory->id;
            }

            // 3. Handle Brand
            $brandId = $validated['brand_id'] ?? null;
            if (isset($validated['brand']['is_new']) && $validated['brand']['is_new']) {
                $brand = Brand::create(['name' => $validated['brand']['name']]);
                $brandId = $brand->id;
            }

            // 4. Create Product (is_verified = 0 always)
            $product = Product::create([
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'brand_id' => $brandId,
                'model' => $validated['name'],
                'psm_code' => $validated['psm_code'],
                // 'rental_software_code' => $validated['rental_software_code'],
                'is_verified' => 0,
            ]);

            // 5. Create Equipment for the user's company
            $user = Auth::user();
            Equipment::create([
                'user_id' => $user->id,
                'company_id' => $user->company_id, // assumes User has company_id field
                'product_id' => $product->id,
                'quantity' => $validated['quantity'],
                'price' => $validated['price'],
                'software_code' => $validated['rental_software_code'],
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $product,
                'message' => 'Product and equipment created successfully',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
