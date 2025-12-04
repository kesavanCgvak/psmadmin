<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\Company;
use App\Services\BulkDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RegionController extends Controller
{
    /**
     * Display a listing of the regions.
     */
    public function index()
    {
        $regions = Region::withCount('countries')->get();
        return view('admin.geography.regions.index', compact('regions'));
    }

    /**
     * Show the form for creating a new region.
     */
    public function create()
    {
        return view('admin.geography.regions.create');
    }

    /**
     * Store a newly created region in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150|unique:regions,name',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        Region::create([
            'name' => $request->name,
        ]);

        return redirect()->route('regions.index')
            ->with('success', 'Region created successfully.');
    }

    /**
     * Display the specified region.
     */
    public function show(Region $region)
    {
        $region->load('countries');
        return view('admin.geography.regions.show', compact('region'));
    }

    /**
     * Show the form for editing the specified region.
     */
    public function edit(Region $region)
    {
        return view('admin.geography.regions.edit', compact('region'));
    }

    /**
     * Update the specified region in storage.
     */
    public function update(Request $request, Region $region)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150|unique:regions,name,' . $region->id,
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $region->update([
            'name' => $request->name,
        ]);

        return redirect()->route('regions.index')
            ->with('success', 'Region updated successfully.');
    }

    /**
     * Remove the specified region from storage.
     */
    public function destroy(Region $region)
    {
        // Relation checks before deletion
        if ($region->countries()->exists()) {
            return redirect()->route('regions.index')
                ->with('error', 'Cannot delete — this region has assigned countries.');
        }
        if (\App\Models\Company::where('region_id', $region->id)->exists()) {
            return redirect()->route('regions.index')
                ->with('error', 'Cannot delete — this region is used by one or more companies.');
        }

        try {
            $region->delete();
            return redirect()->route('regions.index')
                ->with('success', 'Region deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('regions.index')
                ->with('error', 'Cannot delete region. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple regions.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'region_ids' => 'required|array',
            'region_ids.*' => 'exists:regions,id'
        ]);

        $regions = Region::whereIn('id', $request->region_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($regions->all(), [
            function (Region $region) {
                if ($region->countries()->exists()) {
                    return 'Cannot delete — this region has assigned countries.';
                }
                if (Company::where('region_id', $region->id)->exists()) {
                    return 'Cannot delete — this region is used by one or more companies.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} region(s).";
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

        $message = implode(' ', $messageParts) ?: 'No regions were deleted.';

        $success = $deletedCount > 0;
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => $success,
                'message' => $message,
                'deleted_count' => $deletedCount,
                'blocked' => $blocked,
                'errors' => $errors,
            ]);
        }

        return redirect()->route('regions.index')
            ->with($success ? 'success' : 'error', $message);
    }
}

