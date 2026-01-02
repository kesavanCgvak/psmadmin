<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\BulkDeletionService;
use App\Traits\NormalizesName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BrandController extends Controller
{
    use NormalizesName;
    /**
     * Display a listing of the brands.
     */
    public function index()
    {
        $brands = Brand::withCount('products')->get();
        return view('admin.products.brands.index', compact('brands'));
    }

    /**
     * Show the form for creating a new brand.
     */
    public function create()
    {
        return view('admin.products.brands.create');
    }

    /**
     * Store a newly created brand in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Normalize the name for duplicate checking
        $normalizedName = $this->normalizeName($request->name);
        
        // Check for duplicate using normalized name (case-insensitive, symbol-agnostic)
        $existingBrand = Brand::all()->first(function ($brand) use ($normalizedName) {
            return $this->normalizeName($brand->name) === $normalizedName;
        });

        if ($existingBrand) {
            return redirect()->back()
                ->withErrors(['name' => 'A brand with this name already exists.'])
                ->withInput();
        }

        Brand::create([
            'name' => trim($request->name),
        ]);

        return redirect()->route('admin.brands.index')
            ->with('success', 'Brand created successfully.');
    }

    /**
     * Display the specified brand.
     */
    public function show(Brand $brand)
    {
        $brand->load(['products.category', 'products.subCategory']);
        return view('admin.products.brands.show', compact('brand'));
    }

    /**
     * Show the form for editing the specified brand.
     */
    public function edit(Brand $brand)
    {
        return view('admin.products.brands.edit', compact('brand'));
    }

    /**
     * Update the specified brand in storage.
     */
    public function update(Request $request, Brand $brand)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Normalize the name for duplicate checking
        $normalizedName = $this->normalizeName($request->name);
        $currentNormalizedName = $this->normalizeName($brand->name);
        
        // Allow update if the normalized name is unchanged
        if ($normalizedName === $currentNormalizedName) {
            $brand->update([
                'name' => trim($request->name),
            ]);

            return redirect()->route('admin.brands.index')
                ->with('success', 'Brand updated successfully.');
        }
        
        // Check for duplicate using normalized name (excluding current brand)
        $existingBrand = Brand::where('id', '!=', $brand->id)
            ->get()
            ->first(function ($b) use ($normalizedName) {
                return $this->normalizeName($b->name) === $normalizedName;
            });

        if ($existingBrand) {
            return redirect()->back()
                ->withErrors(['name' => 'A brand with this name already exists.'])
                ->withInput();
        }

        $brand->update([
            'name' => trim($request->name),
        ]);

        return redirect()->route('admin.brands.index')
            ->with('success', 'Brand updated successfully.');
    }

    /**
     * Remove the specified brand from storage.
     */
    public function destroy(Brand $brand)
    {
        // Relation checks before deletion
        if ($brand->products()->exists()) {
            return redirect()->route('admin.brands.index')
                ->with('error', 'Cannot delete — this brand has products.');
        }

        try {
            $brand->delete();
            return redirect()->route('admin.brands.index')
                ->with('success', 'Brand deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.brands.index')
                ->with('error', 'Cannot delete brand. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple brands.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'brand_ids' => 'required|array',
            'brand_ids.*' => 'exists:brands,id'
        ]);

        $brands = Brand::whereIn('id', $request->brand_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($brands->all(), [
            function (Brand $brand) {
                if ($brand->products()->exists()) {
                    return 'Cannot delete — this brand has products.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} brand/brands.";
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

        $message = implode(' ', $messageParts) ?: 'No brands were deleted.';
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

        return redirect()->route('admin.brands.index')
            ->with($success ? 'success' : 'error', $message);
    }
}

