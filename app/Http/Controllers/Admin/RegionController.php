<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
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
        try {
            $region->delete();
            return redirect()->route('regions.index')
                ->with('success', 'Region deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('regions.index')
                ->with('error', 'Cannot delete region. It may have associated countries.');
        }
    }
}

