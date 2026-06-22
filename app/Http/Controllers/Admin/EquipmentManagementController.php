<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentImage;
use App\Models\LinearUnit;
use App\Models\Product;
use App\Models\Company;
use App\Models\User;
use App\Models\WeightUnit;
use App\Support\CompanyInventorySpecs;
use App\Support\InventoryImageManagementService;
use App\Support\InventoryImageSyncService;
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

        $equipment = Equipment::create($this->equipmentPayload($request));
        $productId = (int) $request->input('product_id');
        if ($productId > 0) {
            InventoryImageSyncService::syncMasterToEquipment($productId, (int) $equipment->id, true);
        }

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
            'product.masterImages',
            'user',
            'images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
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
        $equipment->load([
            'product.brand',
            'images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
        ]);
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

        $previousProductId = (int) $equipment->product_id;
        $equipment->update($this->equipmentPayload($request));
        $newProductId = (int) $equipment->fresh()->product_id;
        if ($newProductId > 0 && $newProductId !== $previousProductId) {
            InventoryImageSyncService::syncMasterToEquipment($newProductId, (int) $equipment->id, true);
        }

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
                InventoryImageManagementService::deleteLocalFileIfStored($image->image_path);
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
                    InventoryImageManagementService::deleteLocalFileIfStored($image->image_path);
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
        $product->load(['masterImages' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);

        return response()->json([
            'success' => true,
            'data' => array_merge(
                CompanyInventorySpecs::productSpecsForJson($product),
                [
                    'master_images' => $product->masterImages->map(fn ($img) => [
                        'id' => $img->id,
                        'image_path' => $img->image_path,
                        'is_primary' => (bool) $img->is_primary,
                        'sort_order' => $img->sort_order,
                    ])->values()->all(),
                ]
            ),
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

    public function storeImage(Request $request, Equipment $equipment)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'image_path' => 'nullable|string|max:512',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        if (!$request->hasFile('image') && !$request->filled('image_path')) {
            return redirect()->back()
                ->withErrors(['image' => 'Provide an image file or URL/path.'])
                ->withInput();
        }

        try {
            InventoryImageManagementService::addEquipmentImage(
                (int) $equipment->id,
                $request->file('image'),
                $request->input('image_path')
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['image_path' => $e->getMessage()])->withInput();
        }

        return redirect()->back()->with('success', 'Equipment image added.');
    }

    public function updateImage(Request $request, Equipment $equipment, EquipmentImage $equipmentImage)
    {
        if ((int) $equipmentImage->equipment_id !== (int) $equipment->id) {
            abort(404);
        }

        $validator = Validator::make($request->all(), [
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'image_path' => 'nullable|string|max:512',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }

        if (!$request->hasFile('image') && !$request->filled('image_path')) {
            return redirect()->back()->withErrors(['image' => 'Provide a file or URL/path to update.']);
        }

        try {
            if ($request->hasFile('image')) {
                InventoryImageManagementService::replaceEquipmentImageFile($equipmentImage, $request->file('image'));
            } else {
                InventoryImageManagementService::replaceEquipmentImagePath($equipmentImage, (string) $request->input('image_path'));
            }
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['image_path' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Equipment image updated.');
    }

    public function destroyImage(Equipment $equipment, EquipmentImage $equipmentImage)
    {
        if ((int) $equipmentImage->equipment_id !== (int) $equipment->id) {
            abort(404);
        }

        InventoryImageManagementService::deleteEquipmentImage($equipmentImage);

        return redirect()->back()->with('success', 'Equipment image removed.');
    }

    public function setPrimaryImage(Equipment $equipment, EquipmentImage $equipmentImage)
    {
        InventoryImageManagementService::setEquipmentPrimary((int) $equipment->id, $equipmentImage);

        return redirect()->back()->with('success', 'Primary equipment image updated.');
    }

    public function reorderImages(Request $request, Equipment $equipment)
    {
        $request->validate([
            'order' => 'required|array|min:1',
            'order.*' => 'integer',
        ]);

        try {
            InventoryImageManagementService::reorderEquipmentImages(
                (int) $equipment->id,
                $request->input('order', [])
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Image order saved.');
    }
}
