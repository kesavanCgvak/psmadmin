<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\LinearUnit;
use App\Models\Product;
use App\Models\Company;
use App\Models\User;
use App\Models\WeightUnit;
use App\Support\CompanyInventorySpecs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EquipmentManagementController extends Controller
{
    /**
     * Display a listing of all equipment.
     */
    public function index()
    {
        $equipments = Equipment::with([
            'company',
            'product.brand',
            'product.category',
            'user',
            'linearUnit:id,code',
            'weightUnit:id,code',
        ])->get();

        return view('admin.companies.equipment.index', compact('equipments'));
    }

    /**
     * Show the form for creating new equipment.
     */
    public function create()
    {
        $companies = Company::orderBy('name')->get();
        $users = User::orderBy('username')->get();
        $linearUnits = LinearUnit::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']);
        $weightUnits = WeightUnit::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']);
        $selectedProduct = $this->resolveSelectedProduct(old('product_id'));

        return view('admin.companies.equipment.create', compact(
            'companies',
            'users',
            'linearUnits',
            'weightUnits',
            'selectedProduct'
        ));
    }

    /**
     * Store a newly created equipment in storage.
     */
    public function store(Request $request)
    {
        $validator = $this->makeValidator($request);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        Equipment::create($this->equipmentPayload($request));

        return redirect()->route('admin.equipment.index')
            ->with('success', 'Equipment created successfully.');
    }

    /**
     * Display the specified equipment.
     */
    public function show(Equipment $equipment)
    {
        $equipment->load([
            'company',
            'product.brand',
            'product.category',
            'product.subCategory',
            'user',
            'images',
            'linearUnit:id,code,name',
            'weightUnit:id,code,name',
        ]);

        return view('admin.companies.equipment.show', compact('equipment'));
    }

    /**
     * Show the form for editing the specified equipment.
     */
    public function edit(Equipment $equipment)
    {
        $equipment->load('product.brand');
        $companies = Company::orderBy('name')->get();
        $users = User::where('company_id', $equipment->company_id)->orderBy('username')->get();
        $linearUnits = LinearUnit::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']);
        $weightUnits = WeightUnit::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']);
        $selectedProduct = $this->resolveSelectedProduct(old('product_id', $equipment->product_id), $equipment->product);

        return view('admin.companies.equipment.edit', compact(
            'equipment',
            'companies',
            'users',
            'linearUnits',
            'weightUnits',
            'selectedProduct'
        ));
    }

    /**
     * Update the specified equipment in storage.
     */
    public function update(Request $request, Equipment $equipment)
    {
        $validator = $this->makeValidator($request);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $equipment->update($this->equipmentPayload($request));

        return redirect()->route('admin.equipment.index')
            ->with('success', 'Equipment updated successfully.');
    }

    /**
     * Remove the specified equipment from storage.
     */
    public function destroy(Equipment $equipment)
    {
        try {
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

    /**
     * Fetch inventory_master physical specs for equipment form auto-fill.
     */
    public function getProductInventorySpecs(Product $product)
    {
        return response()->json([
            'success' => true,
            'data' => CompanyInventorySpecs::productSpecsForJson($product),
        ]);
    }

    private function makeValidator(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:inventory_master,id',
            'quantity' => 'required|integer|min:1',
            'rental_price' => 'required|numeric|min:0',
            'software_code' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'height' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'length' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'linear_unit_id' => 'nullable|exists:linear_units,id',
            'weight_unit_id' => 'nullable|exists:weight_units,id',
            'country_of_origin' => 'nullable|string|max:100',
            'hsn_code' => 'nullable|string|max:20',
        ]);
    }

    private function resolveSelectedProduct(mixed $productId, ?Product $fallback = null): ?Product
    {
        if ($productId) {
            return Product::with('brand:id,name')->find($productId) ?? $fallback;
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentPayload(Request $request): array
    {
        return $request->only(array_merge([
            'company_id',
            'user_id',
            'product_id',
            'quantity',
            'rental_price',
            'software_code',
            'description',
        ], CompanyInventorySpecs::FIELDS));
    }
}
