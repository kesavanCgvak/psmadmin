<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use App\Models\Category;
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

        return redirect()->route('subcategories.index')
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

        return redirect()->route('subcategories.index')
            ->with('success', 'Sub-category updated successfully.');
    }

    /**
     * Remove the specified sub-category from storage.
     */
    public function destroy(SubCategory $subcategory)
    {
        try {
            $subcategory->delete();
            return redirect()->route('subcategories.index')
                ->with('success', 'Sub-category deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('subcategories.index')
                ->with('error', 'Cannot delete sub-category. It may have associated products.');
        }
    }
}

