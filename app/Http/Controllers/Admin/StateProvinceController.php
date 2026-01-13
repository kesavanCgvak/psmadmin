<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StateProvince;
use App\Models\Country;
use App\Models\Region;
use App\Models\Company;
use App\Services\BulkDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StateProvinceController extends Controller
{
    /**
     * Display a listing of the states/provinces.
     */
    public function index()
    {
        $states = StateProvince::with('country')->get();
        return view('admin.geography.states.index', compact('states'));
    }

    /**
     * Show the form for creating a new state/province.
     */
    public function create()
    {
        $regions = Region::orderBy('name')->get();
        $countries = collect(); // Empty collection - will load via AJAX
        $types = ['state', 'province', 'territory', 'region', 'district', 'federal_entity'];
        return view('admin.geography.states.create', compact('regions', 'countries', 'types'));
    }

    /**
     * Store a newly created state/province in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'region_id' => 'required|exists:regions,id',
            'country_id' => 'required|exists:countries,id',
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:10',
            'type' => 'required|in:state,province,territory,region,district,federal_entity',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Check for duplicate using normalized name comparison within the same country
        if (StateProvince::isDuplicate($request->name, $request->country_id)) {
            return redirect()->back()
                ->withErrors(['name' => 'A state/province with this name already exists in the selected country.'])
                ->withInput();
        }

        StateProvince::create($request->all());

        return redirect()->route('states.index')
            ->with('success', 'State/Province created successfully.');
    }

    /**
     * Display the specified state/province.
     */
    public function show(StateProvince $state)
    {
        $state->load(['country', 'cities']);
        return view('admin.geography.states.show', compact('state'));
    }

    /**
     * Show the form for editing the specified state/province.
     */
    public function edit(StateProvince $state)
    {
        $regions = Region::orderBy('name')->get();
        $countries = Country::where('region_id', $state->country->region_id ?? null)->orderBy('name')->get();
        $types = ['state', 'province', 'territory', 'region', 'district', 'federal_entity'];
        return view('admin.geography.states.edit', compact('state', 'regions', 'countries', 'types'));
    }

    /**
     * Update the specified state/province in storage.
     */
    public function update(Request $request, StateProvince $state)
    {
        $validator = Validator::make($request->all(), [
            'country_id' => 'required|exists:countries,id',
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:10',
            'type' => 'required|in:state,province,territory,region,district,federal_entity',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Check for duplicate using normalized name comparison within the same country (excluding current record)
        if (StateProvince::isDuplicate($request->name, $request->country_id, $state->id)) {
            return redirect()->back()
                ->withErrors(['name' => 'A state/province with this name already exists in the selected country.'])
                ->withInput();
        }

        $state->update($request->all());

        return redirect()->route('states.index')
            ->with('success', 'State/Province updated successfully.');
    }

    /**
     * Remove the specified state/province from storage.
     */
    public function destroy(StateProvince $state)
    {
        // Relation checks before deletion
        if ($state->cities()->exists()) {
            return redirect()->route('states.index')
                ->with('error', 'Cannot delete — this state/province has associated cities.');
        }
        if (\App\Models\Company::where('state_id', $state->id)->exists()) {
            return redirect()->route('states.index')
                ->with('error', 'Cannot delete — this state/province is used by one or more companies.');
        }

        try {
            $state->delete();
            return redirect()->route('states.index')
                ->with('success', 'State/Province deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('states.index')
                ->with('error', 'Cannot delete state/province. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple states/provinces.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'state_ids' => 'required|array',
            'state_ids.*' => 'exists:states_provinces,id'
        ]);

        $states = StateProvince::whereIn('id', $request->state_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($states->all(), [
            function (StateProvince $state) {
                if ($state->cities()->exists()) {
                    return 'Cannot delete — this state/province has associated cities.';
                }
                if (Company::where('state_id', $state->id)->exists()) {
                    return 'Cannot delete — this state/province is used by one or more companies.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} state/province(s).";
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

        $message = implode(' ', $messageParts) ?: 'No states/provinces were deleted.';
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

        return redirect()->route('states.index')
            ->with($success ? 'success' : 'error', $message);
    }

    /**
     * Get countries by region (AJAX endpoint)
     */
    public function getCountriesByRegion($regionId)
    {
        $countries = Country::where('region_id', $regionId)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code']);

        return response()->json($countries);
    }
}

