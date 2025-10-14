<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Region;
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

        $country->update($request->all());

        return redirect()->route('countries.index')
            ->with('success', 'Country updated successfully.');
    }

    /**
     * Remove the specified country from storage.
     */
    public function destroy(Country $country)
    {
        try {
            $country->delete();
            return redirect()->route('countries.index')
                ->with('success', 'Country deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('countries.index')
                ->with('error', 'Cannot delete country. It may have associated states/provinces or cities.');
        }
    }
}

