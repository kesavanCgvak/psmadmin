<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\StateProvince;
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
        $countries = Country::orderBy('name')->get();
        $states = StateProvince::orderBy('name')->get();
        return view('admin.geography.cities.create', compact('countries', 'states'));
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
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
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
        $countries = Country::orderBy('name')->get();
        $states = StateProvince::where('country_id', $city->country_id)->orderBy('name')->get();
        return view('admin.geography.cities.edit', compact('city', 'countries', 'states'));
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
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
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
        try {
            $city->delete();
            return redirect()->route('cities.index')
                ->with('success', 'City deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('cities.index')
                ->with('error', 'Cannot delete city. It may be associated with companies or other records.');
        }
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
}

