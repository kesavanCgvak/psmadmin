<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Region;
use App\Models\Company;
use App\Services\BulkDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CountryController extends Controller
{
    /**
     * Display a listing of the countries.
     */
    public function index()
    {
        $countries = Country::with('region')->get();
        return view('admin.geography.countries.index', compact('countries'));
    }

    /**
     * Show the form for creating a new country.
     */
    public function create()
    {
        $regions = Region::orderBy('name')->get();
        return view('admin.geography.countries.create', compact('regions'));
    }

    /**
     * Store a newly created country in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'region_id' => 'required|exists:regions,id',
            'name' => 'required|string|max:150',
            'iso_code' => 'required|string|size:2|unique:countries,iso_code',
            'phone_code' => 'nullable|string|max:10',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Check for duplicate using normalized name comparison within the same region
        if (Country::isDuplicate($request->name, $request->region_id)) {
            return redirect()->back()
                ->withErrors(['name' => 'A country with this name already exists in the selected region.'])
                ->withInput();
        }

        Country::create($request->all());

        return redirect()->route('countries.index')
            ->with('success', 'Country created successfully.');
    }

    /**
     * Display the specified country.
     */
    public function show(Country $country)
    {
        $country->load(['region', 'statesProvinces', 'cities']);
        return view('admin.geography.countries.show', compact('country'));
    }

    /**
     * Show the form for editing the specified country.
     */
    public function edit(Country $country)
    {
        $regions = Region::orderBy('name')->get();
        return view('admin.geography.countries.edit', compact('country', 'regions'));
    }

    /**
     * Update the specified country in storage.
     */
    public function update(Request $request, Country $country)
    {
        $validator = Validator::make($request->all(), [
            'region_id' => 'required|exists:regions,id',
            'name' => 'required|string|max:150',
            'iso_code' => 'required|string|size:2|unique:countries,iso_code,' . $country->id,
            'phone_code' => 'nullable|string|max:10',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Check for duplicate using normalized name comparison within the same region (excluding current record)
        if (Country::isDuplicate($request->name, $request->region_id, $country->id)) {
            return redirect()->back()
                ->withErrors(['name' => 'A country with this name already exists in the selected region.'])
                ->withInput();
        }

        $country->update($request->all());

        return redirect()->route('countries.index')
            ->with('success', 'Country updated successfully.');
    }

    /**
     * Remove the specified country from storage.
     */
    public function destroy(Country $country)
    {
        // Relation checks before deletion
        if ($country->statesProvinces()->exists()) {
            return redirect()->route('countries.index')
                ->with('error', 'Cannot delete — this country is assigned to one or more states.');
        }
        if ($country->cities()->exists()) {
            return redirect()->route('countries.index')
                ->with('error', 'Cannot delete — this country has associated cities.');
        }
        if (\App\Models\Company::where('country_id', $country->id)->exists()) {
            return redirect()->route('countries.index')
                ->with('error', 'Cannot delete — this country is used by one or more companies.');
        }

        try {
            $country->delete();
            return redirect()->route('countries.index')
                ->with('success', 'Country deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('countries.index')
                ->with('error', 'Cannot delete country. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple countries.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'country_ids' => 'required|array',
            'country_ids.*' => 'exists:countries,id'
        ]);

        $countries = Country::whereIn('id', $request->country_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($countries->all(), [
            function (Country $country) {
                if ($country->statesProvinces()->exists()) {
                    return 'Cannot delete — this country is assigned to one or more states.';
                }
                if ($country->cities()->exists()) {
                    return 'Cannot delete — this country has associated cities.';
                }
                if (Company::where('country_id', $country->id)->exists()) {
                    return 'Cannot delete — this country is used by one or more companies.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} country/countries.";
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

        $message = implode(' ', $messageParts) ?: 'No countries were deleted.';
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

        return redirect()->route('countries.index')
            ->with($success ? 'success' : 'error', $message);
    }
}

