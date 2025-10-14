<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StateProvince;
use App\Models\Country;
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
        $countries = Country::orderBy('name')->get();
        $types = ['state', 'province', 'territory', 'region', 'district', 'federal_entity'];
        return view('admin.geography.states.create', compact('countries', 'types'));
    }

    /**
     * Store a newly created state/province in storage.
     */
    public function store(Request $request)
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
        $countries = Country::orderBy('name')->get();
        $types = ['state', 'province', 'territory', 'region', 'district', 'federal_entity'];
        return view('admin.geography.states.edit', compact('state', 'countries', 'types'));
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

        $state->update($request->all());

        return redirect()->route('states.index')
            ->with('success', 'State/Province updated successfully.');
    }

    /**
     * Remove the specified state/province from storage.
     */
    public function destroy(StateProvince $state)
    {
        try {
            $state->delete();
            return redirect()->route('states.index')
                ->with('success', 'State/Province deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('states.index')
                ->with('error', 'Cannot delete state/province. It may have associated cities.');
        }
    }
}

