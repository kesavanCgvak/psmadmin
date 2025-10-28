<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use App\Models\Category;
use App\Services\BulkDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubCategoryController extends Controller
{
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

        SubCategory::create($request->all());

        return redirect()->route('admin.subcategories.index')
            ->with('success', 'Sub-category created successfully.');
    }

    /**
     * Display the specified sub-category.
     */
    public function show(SubCategory $subcategory)
    {
        $subcategory->load(['category', 'products.brand']);
        return view('admin.products.subcategories.show', compact('subcategory'));
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

        $subcategory->update($request->all());

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
}

