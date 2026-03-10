<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LinearUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class LinearUnitController extends Controller
{
    /**
     * Display a listing of the linear units.
     */
    public function index()
    {
        $linearUnits = LinearUnit::orderBy('code')->get();
        return view('admin.measurement-units.linear-units.index', compact('linearUnits'));
    }

    /**
     * Show the form for creating a new linear unit.
     */
    public function create()
    {
        return view('admin.measurement-units.linear-units.create');
    }

    /**
     * Store a newly created linear unit in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:10|unique:linear_units,code',
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

        LinearUnit::create($data);

        return redirect()->route('admin.linear-units.index')
            ->with('success', 'Linear unit created successfully.');
    }

    /**
     * Show the form for editing the specified linear unit.
     */
    public function edit(LinearUnit $linearUnit)
    {
        return view('admin.measurement-units.linear-units.edit', compact('linearUnit'));
    }

    /**
     * Update the specified linear unit in storage.
     */
    public function update(Request $request, LinearUnit $linearUnit)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'code' => 'required|string|max:10|unique:linear_units,code,' . $linearUnit->id,
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

        $linearUnit->update($data);

        return redirect()->route('admin.linear-units.index')
            ->with('success', 'Linear unit updated successfully.');
    }

    /**
     * Remove the specified linear unit from storage.
     */
    public function destroy(LinearUnit $linearUnit)
    {
        if (Schema::hasColumn('inventory_master', 'linear_unit_id')) {
            if (\DB::table('inventory_master')->where('linear_unit_id', $linearUnit->id)->exists()) {
                return redirect()->route('admin.linear-units.index')
                    ->with('error', 'Cannot delete — this unit is used in inventory.');
            }
        }

        try {
            $linearUnit->delete();
            return redirect()->route('admin.linear-units.index')
                ->with('success', 'Linear unit deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.linear-units.index')
                ->with('error', 'Cannot delete linear unit. ' . $e->getMessage());
        }
    }
}
