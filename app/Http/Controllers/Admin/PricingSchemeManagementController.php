<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingScheme;
use App\Services\BulkDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PricingSchemeManagementController extends Controller
{
    /**
     * Display a listing of the pricing schemes.
     */
    public function index()
    {
        $pricingSchemes = PricingScheme::withCount('companies')->get();
        return view('admin.companies.pricing-schemes.index', compact('pricingSchemes'));
    }

    /**
     * Show the form for creating a new pricing scheme.
     */
    public function create()
    {
        return view('admin.companies.pricing-schemes.create');
    }

    /**
     * Store a newly created pricing scheme in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:255|unique:pricing_schemes,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        PricingScheme::create($request->all());

        return redirect()->route('admin.pricing-schemes.index')
            ->with('success', 'Pricing scheme created successfully.');
    }

    /**
     * Display the specified pricing scheme.
     */
    public function show(PricingScheme $pricingScheme)
    {
        $pricingScheme->load('companies');
        return view('admin.companies.pricing-schemes.show', compact('pricingScheme'));
    }

    /**
     * Show the form for editing the specified pricing scheme.
     */
    public function edit(PricingScheme $pricingScheme)
    {
        return view('admin.companies.pricing-schemes.edit', compact('pricingScheme'));
    }

    /**
     * Update the specified pricing scheme in storage.
     */
    public function update(Request $request, PricingScheme $pricingScheme)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:255|unique:pricing_schemes,code,' . $pricingScheme->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $pricingScheme->update($request->all());

        return redirect()->route('admin.pricing-schemes.index')
            ->with('success', 'Pricing scheme updated successfully.');
    }

    /**
     * Remove the specified pricing scheme from storage.
     */
    public function destroy(PricingScheme $pricingScheme)
    {
        // Relation checks before deletion
        if ($pricingScheme->companies()->exists()) {
            return redirect()->route('admin.pricing-schemes.index')
                ->with('error', 'Cannot delete — this pricing scheme is used by one or more companies.');
        }

        try {
            $pricingScheme->delete();
            return redirect()->route('admin.pricing-schemes.index')
                ->with('success', 'Pricing scheme deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.pricing-schemes.index')
                ->with('error', 'Cannot delete pricing scheme. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple pricing schemes.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'pricing_scheme_ids' => 'required|array',
            'pricing_scheme_ids.*' => 'exists:pricing_schemes,id'
        ]);

        $pricingSchemes = PricingScheme::whereIn('id', $request->pricing_scheme_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($pricingSchemes->all(), [
            function (PricingScheme $pricingScheme) {
                if ($pricingScheme->companies()->exists()) {
                    return 'Cannot delete — this pricing scheme is used by one or more companies.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} pricing scheme(s).";
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

        $message = implode(' ', $messageParts) ?: 'No pricing schemes were deleted.';
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

        return redirect()->route('admin.pricing-schemes.index')
            ->with($success ? 'success' : 'error', $message);
    }
}
