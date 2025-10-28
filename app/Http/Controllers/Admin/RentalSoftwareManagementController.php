<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalSoftware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RentalSoftwareManagementController extends Controller
{
    /**
     * Display a listing of the rental software.
     */
    public function index()
    {
        $rentalSoftwares = RentalSoftware::withCount('companies')->get();
        return view('admin.companies.rental-software.index', compact('rentalSoftwares'));
    }

    /**
     * Show the form for creating a new rental software.
     */
    public function create()
    {
        return view('admin.companies.rental-software.create');
    }

    /**
     * Store a newly created rental software in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:rental_softwares,name',
            'description' => 'nullable|string',
            'version' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        RentalSoftware::create($request->all());

        return redirect()->route('admin.rental-software.index')
            ->with('success', 'Rental software created successfully.');
    }

    /**
     * Display the specified rental software.
     */
    public function show(RentalSoftware $rentalSoftware)
    {
        $rentalSoftware->load('companies');
        return view('admin.companies.rental-software.show', compact('rentalSoftware'));
    }

    /**
     * Show the form for editing the specified rental software.
     */
    public function edit(RentalSoftware $rentalSoftware)
    {
        return view('admin.companies.rental-software.edit', compact('rentalSoftware'));
    }

    /**
     * Update the specified rental software in storage.
     */
    public function update(Request $request, RentalSoftware $rentalSoftware)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:rental_softwares,name,' . $rentalSoftware->id,
            'description' => 'nullable|string',
            'version' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $rentalSoftware->update($request->all());

        return redirect()->route('admin.rental-software.index')
            ->with('success', 'Rental software updated successfully.');
    }

    /**
     * Remove the specified rental software from storage.
     */
    public function destroy(RentalSoftware $rentalSoftware)
    {
        try {
            $rentalSoftware->delete();
            return redirect()->route('admin.rental-software.index')
                ->with('success', 'Rental software deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.rental-software.index')
                ->with('error', 'Cannot delete rental software. It may be used by companies.');
        }
    }

    /**
     * Bulk delete multiple rental software.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'rental_software_ids' => 'required|array',
            'rental_software_ids.*' => 'exists:rental_softwares,id'
        ]);

        $rentalSoftwareIds = $request->rental_software_ids;
        $deletedCount = 0;
        $errors = [];

        foreach ($rentalSoftwareIds as $rentalSoftwareId) {
            $rentalSoftware = RentalSoftware::find($rentalSoftwareId);

            if (!$rentalSoftware) {
                continue;
            }

            try {
                $rentalSoftware->delete();
                $deletedCount++;
            } catch (\Exception $e) {
                $errors[] = "Failed to delete rental software: {$rentalSoftware->name} - " . $e->getMessage();
            }
        }

        if ($deletedCount > 0) {
            $message = "Successfully deleted {$deletedCount} rental software.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'deleted_count' => $deletedCount,
                    'errors' => $errors
                ]);
            }

            return redirect()->route('admin.rental-software.index')
                ->with('success', $message);
        } else {
            $message = 'No rental software were deleted. ' . (!empty($errors) ? implode(', ', $errors) : '');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'deleted_count' => 0,
                    'errors' => $errors
                ]);
            }

            return redirect()->route('admin.rental-software.index')
                ->with('error', $message);
        }
    }
}

