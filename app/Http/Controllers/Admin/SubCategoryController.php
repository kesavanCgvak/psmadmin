<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use App\Models\Category;
use App\Models\Product;
use App\Services\BulkDeletionService;
use App\Traits\NormalizesName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SubCategoryController extends Controller
{
    use NormalizesName;
    /**
     * Display a listing of the sub-categories.
     */
    public function index()
    {
        $subCategories = SubCategory::with('category')->withCount('products')->get();
        return view('admin.products.subcategories.index', compact('subCategories'));
    }

    /**
     * Show the form for creating a new sub-category.
     */
    public function create()
    {
        $categories = Category::orderBy('name')->get();
        return view('admin.products.subcategories.create', compact('categories'));
    }

    /**
     * Store a newly created sub-category in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Normalize the name for duplicate checking
        $normalizedName = $this->normalizeName($request->name);
        
        // Check for duplicate sub-category under the same category using normalized name
        $existingSubCategory = SubCategory::where('category_id', $request->category_id)
            ->get()
            ->first(function ($subCategory) use ($normalizedName) {
                return $this->normalizeName($subCategory->name) === $normalizedName;
            });

        if ($existingSubCategory) {
            return redirect()->back()
                ->withErrors(['name' => 'A sub-category with this name already exists under the selected category.'])
                ->withInput();
        }

        SubCategory::create([
            'category_id' => $request->category_id,
            'name' => trim($request->name),
        ]);

        return redirect()->route('admin.subcategories.index')
            ->with('success', 'Sub-category created successfully.');
    }

    /**
     * Display the specified sub-category.
     */
    public function show(SubCategory $subcategory)
    {
        $subcategory->load(['category', 'products.brand']);
        // Get all subcategories for the move dropdown (excluding current one)
        $allSubCategories = SubCategory::where('id', '!=', $subcategory->id)
            ->with('category')
            ->orderBy('name')
            ->get()
            ->groupBy(function ($subcat) {
                return $subcat->category->name ?? 'Uncategorized';
            });
        return view('admin.products.subcategories.show', compact('subcategory', 'allSubCategories'));
    }

    /**
     * Show the form for editing the specified sub-category.
     */
    public function edit(SubCategory $subcategory)
    {
        $categories = Category::orderBy('name')->get();
        return view('admin.products.subcategories.edit', compact('subcategory', 'categories'));
    }

    /**
     * Update the specified sub-category in storage.
     */
    public function update(Request $request, SubCategory $subcategory)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Normalize the name for duplicate checking
        $normalizedName = $this->normalizeName($request->name);
        $currentNormalizedName = $this->normalizeName($subcategory->name);
        
        // Allow update if the normalized name is unchanged and category is unchanged
        if ($normalizedName === $currentNormalizedName && $subcategory->category_id == $request->category_id) {
            $subcategory->update([
                'category_id' => $request->category_id,
                'name' => trim($request->name),
            ]);

            return redirect()->route('admin.subcategories.index')
                ->with('success', 'Sub-category updated successfully.');
        }
        
        // Check for duplicate sub-category under the same category using normalized name (excluding current sub-category)
        $existingSubCategory = SubCategory::where('category_id', $request->category_id)
            ->where('id', '!=', $subcategory->id)
            ->get()
            ->first(function ($subCat) use ($normalizedName) {
                return $this->normalizeName($subCat->name) === $normalizedName;
            });

        if ($existingSubCategory) {
            return redirect()->back()
                ->withErrors(['name' => 'A sub-category with this name already exists under the selected category.'])
                ->withInput();
        }

        $subcategory->update([
            'category_id' => $request->category_id,
            'name' => trim($request->name),
        ]);

        return redirect()->route('admin.subcategories.index')
            ->with('success', 'Sub-category updated successfully.');
    }

    /**
     * Remove the specified sub-category from storage.
     */
    public function destroy(SubCategory $subcategory)
    {
        // Relation checks before deletion
        if ($subcategory->products()->exists()) {
            return redirect()->route('admin.subcategories.index')
                ->with('error', 'Cannot delete — this sub-category has products.');
        }

        try {
            $subcategory->delete();
            return redirect()->route('admin.subcategories.index')
                ->with('success', 'Sub-category deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.subcategories.index')
                ->with('error', 'Cannot delete sub-category. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple sub-categories.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'subcategory_ids' => 'required|array',
            'subcategory_ids.*' => 'exists:sub_categories,id'
        ]);

        $subcategories = SubCategory::whereIn('id', $request->subcategory_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($subcategories->all(), [
            function (SubCategory $subcategory) {
                if ($subcategory->products()->exists()) {
                    return 'Cannot delete — this sub-category has products.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} sub-category/sub-categories.";
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

        $message = implode(' ', $messageParts) ?: 'No sub-categories were deleted.';
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

        return redirect()->route('admin.subcategories.index')
            ->with($success ? 'success' : 'error', $message);
    }

    /**
     * Move products from one sub-category to another.
     */
    public function moveProducts(Request $request, SubCategory $subcategory)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required|exists:products,id',
            'target_subcategory_id' => 'required|exists:sub_categories,id',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $productIds = $request->input('product_ids');
        $targetSubcategoryId = $request->input('target_subcategory_id');

        // Verify all products belong to the current subcategory
        $products = Product::whereIn('id', $productIds)
            ->where('sub_category_id', $subcategory->id)
            ->get();

        if ($products->count() !== count($productIds)) {
            $message = 'Some selected products do not belong to this sub-category.';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message
                ], 422);
            }
            return redirect()->back()->with('error', $message);
        }

        try {
            DB::beginTransaction();

            // Update products to new subcategory
            Product::whereIn('id', $productIds)
                ->update(['sub_category_id' => $targetSubcategoryId]);

            DB::commit();

            $targetSubcategory = SubCategory::find($targetSubcategoryId);
            $message = "Successfully moved {$products->count()} product(s) to '{$targetSubcategory->name}'.";

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'moved_count' => $products->count()
                ]);
            }

            return redirect()->route('admin.subcategories.show', $subcategory)
                ->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            $message = 'Failed to move products: ' . $e->getMessage();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message
                ], 500);
            }

            return redirect()->back()->with('error', $message);
        }
    }
}

