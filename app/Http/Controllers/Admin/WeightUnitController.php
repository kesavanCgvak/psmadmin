<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WeightUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class WeightUnitController extends Controller
{
    /**
     * Display a listing of the weight units.
     */
    public function index()
    {
        $weightUnits = WeightUnit::orderBy('code')->get();
        return view('admin.measurement-units.weight-units.index', compact('weightUnits'));
    }

    /**
     * Show the form for creating a new weight unit.
     */
    public function create()
    {
        return view('admin.measurement-units.weight-units.create');
    }

    /**
     * Store a newly created weight unit in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:10|unique:weight_units,code',
            'system' => 'required|in:imperial,metric',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $request->only(['name', 'code', 'system']);
        $data['is_active'] = $request->boolean('is_active');

        WeightUnit::create($data);

        return redirect()->route('admin.weight-units.index')
            ->with('success', 'Weight unit created successfully.');
    }

    /**
     * Show the form for editing the specified weight unit.
     */
    public function edit(WeightUnit $weightUnit)
    {
        return view('admin.measurement-units.weight-units.edit', compact('weightUnit'));
    }

    /**
     * Update the specified weight unit in storage.
     */
    public function update(Request $request, WeightUnit $weightUnit)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:10|unique:weight_units,code,' . $weightUnit->id,
            'system' => 'required|in:imperial,metric',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $request->only(['name', 'code', 'system']);
        $data['is_active'] = $request->boolean('is_active');

        $weightUnit->update($data);

        return redirect()->route('admin.weight-units.index')
            ->with('success', 'Weight unit updated successfully.');
    }

    /**
     * Remove the specified weight unit from storage.
     */
    public function destroy(WeightUnit $weightUnit)
    {
        if (Schema::hasColumn('inventory_master', 'weight_unit_id')) {
            if (\DB::table('inventory_master')->where('weight_unit_id', $weightUnit->id)->exists()) {
                return redirect()->route('admin.weight-units.index')
                    ->with('error', 'Cannot delete — this unit is used in inventory.');
            }
        }

        try {
            $weightUnit->delete();
            return redirect()->route('admin.weight-units.index')
                ->with('success', 'Weight unit deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.weight-units.index')
                ->with('error', 'Cannot delete weight unit. ' . $e->getMessage());
        }
    }
}
