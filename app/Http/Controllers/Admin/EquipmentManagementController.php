<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EquipmentManagementController extends Controller
{
    /**
     * Display a listing of all equipment.
     */
    public function index()
    {
        $equipments = Equipment::with(['company', 'product.brand', 'product.category', 'user'])->get();
        return view('admin.companies.equipment.index', compact('equipments'));
    }

    /**
     * Show the form for creating new equipment.
     */
    public function create()
    {
        $companies = Company::orderBy('name')->get();
        $products = Product::with('brand')->orderBy('model')->get();
        $users = User::orderBy('username')->get();

        return view('admin.companies.equipment.create', compact('companies', 'products', 'users'));
    }

    /**
     * Store a newly created equipment in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'software_code' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        Equipment::create($request->all());

        return redirect()->route('admin.equipment.index')
            ->with('success', 'Equipment created successfully.');
    }

    /**
     * Display the specified equipment.
     */
    public function show(Equipment $equipment)
    {
        $equipment->load(['company', 'product.brand', 'product.category', 'product.subCategory', 'user', 'images']);
        return view('admin.companies.equipment.show', compact('equipment'));
    }

    /**
     * Show the form for editing the specified equipment.
     */
    public function edit(Equipment $equipment)
    {
        $companies = Company::orderBy('name')->get();
        $products = Product::with('brand')->orderBy('model')->get();
        $users = User::where('company_id', $equipment->company_id)->orderBy('username')->get();

        return view('admin.companies.equipment.edit', compact('equipment', 'companies', 'products', 'users'));
    }

    /**
     * Update the specified equipment in storage.
     */
    public function update(Request $request, Equipment $equipment)
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'software_code' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $equipment->update($request->all());

        return redirect()->route('admin.equipment.index')
            ->with('success', 'Equipment updated successfully.');
    }

    /**
     * Remove the specified equipment from storage.
     */
    public function destroy(Equipment $equipment)
    {
        try {
            // Delete associated images
            foreach ($equipment->images as $image) {
                $imagePath = public_path($image->image_path);
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
                $image->delete();
            }

            $equipment->delete();

            return redirect()->route('admin.equipment.index')
                ->with('success', 'Equipment deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.equipment.index')
                ->with('error', 'Cannot delete equipment. Error: ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple equipment.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'equipment_ids' => 'required|array',
            'equipment_ids.*' => 'exists:equipment,id'
        ]);

        $equipmentIds = $request->equipment_ids;
        $deletedCount = 0;
        $errors = [];

        foreach ($equipmentIds as $equipmentId) {
            $equipment = Equipment::find($equipmentId);

            if (!$equipment) {
                continue;
            }

            try {
                // Delete associated images
                foreach ($equipment->images as $image) {
                    $imagePath = public_path($image->image_path);
                    if (file_exists($imagePath)) {
                        unlink($imagePath);
                    }
                    $image->delete();
                }

                $equipment->delete();
                $deletedCount++;
            } catch (\Exception $e) {
                $errors[] = "Failed to delete equipment ID {$equipment->id} - " . $e->getMessage();
            }
        }

        if ($deletedCount > 0) {
            $message = "Successfully deleted {$deletedCount} equipment.";
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

            return redirect()->route('admin.equipment.index')
                ->with('success', $message);
        } else {
            $message = 'No equipment were deleted. ' . (!empty($errors) ? implode(', ', $errors) : '');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'deleted_count' => 0,
                    'errors' => $errors
                ]);
            }

            return redirect()->route('admin.equipment.index')
                ->with('error', $message);
        }
    }

    /**
     * Get users by company (AJAX endpoint)
     */
    public function getUsersByCompany($companyId)
    {
        $users = User::where('company_id', $companyId)
            ->orderBy('username')
            ->get(['id', 'username']);

        return response()->json($users);
    }
}

