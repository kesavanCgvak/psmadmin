<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\StateProvince;
use App\Models\Region;
use App\Models\Company;
use App\Services\BulkDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CityController extends Controller
{
    /**
     * Display a listing of the cities.
     */
    public function index()
    {
        $cities = City::with(['country', 'state'])->get();
        return view('admin.geography.cities.index', compact('cities'));
    }

    /**
     * Show the form for creating a new city.
     */
    public function create()
    {
        $regions = Region::orderBy('name')->get();
        $countries = collect(); // Empty collection - will load via AJAX
        $states = collect(); // Empty collection - will load via AJAX
        return view('admin.geography.cities.create', compact('regions', 'countries', 'states'));
    }

    /**
     * Store a newly created city in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country_id' => 'required|exists:countries,id',
            'state_id' => 'nullable|exists:states_provinces,id',
            'name' => 'required|string|max:150',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        City::create($request->all());

        return redirect()->route('cities.index')
            ->with('success', 'City created successfully.');
    }

    /**
     * Display the specified city.
     */
    public function show(City $city)
    {
        $city->load(['country', 'state']);
        return view('admin.geography.cities.show', compact('city'));
    }

    /**
     * Show the form for editing the specified city.
     */
    public function edit(City $city)
    {
        $regions = Region::orderBy('name')->get();
        $countries = Country::where('region_id', $city->country->region_id ?? null)->orderBy('name')->get();
        $states = StateProvince::where('country_id', $city->country_id)->orderBy('name')->get();
        return view('admin.geography.cities.edit', compact('city', 'regions', 'countries', 'states'));
    }

    /**
     * Update the specified city in storage.
     */
    public function update(Request $request, City $city)
    {
        $validator = Validator::make($request->all(), [
            'country_id' => 'required|exists:countries,id',
            'state_id' => 'nullable|exists:states_provinces,id',
            'name' => 'required|string|max:150',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $city->update($request->all());

        return redirect()->route('cities.index')
            ->with('success', 'City updated successfully.');
    }

    /**
     * Remove the specified city from storage.
     */
    public function destroy(City $city)
    {
        // Relation checks before deletion
        if (\App\Models\Company::where('city_id', $city->id)->exists()) {
            return redirect()->route('cities.index')
                ->with('error', 'Cannot delete — this city is used by one or more companies.');
        }

        try {
            $city->delete();
            return redirect()->route('cities.index')
                ->with('success', 'City deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('cities.index')
                ->with('error', 'Cannot delete city. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple cities.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'city_ids' => 'required|array',
            'city_ids.*' => 'exists:cities,id'
        ]);

        $cities = City::whereIn('id', $request->city_ids)->get();
        $service = new BulkDeletionService();

        $result = $service->deleteWithChecks($cities->all(), [
            function (City $city) {
                if (Company::where('city_id', $city->id)->exists()) {
                    return 'Cannot delete — this city is used by one or more companies.';
                }
                return null;
            },
        ]);

        $deletedCount = $result['deleted_count'];
        $errors = $result['errors'];
        $blocked = $result['blocked'];

        $messageParts = [];
        if ($deletedCount > 0) {
            $messageParts[] = "Successfully deleted {$deletedCount} city/cities.";
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

        $message = implode(' ', $messageParts) ?: 'No cities were deleted.';
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

        return redirect()->route('cities.index')
            ->with($success ? 'success' : 'error', $message);
    }

    /**
     * Get states by country (AJAX endpoint)
     */
    public function getStatesByCountry($countryId)
    {
        $states = StateProvince::where('country_id', $countryId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($states);
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

