<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalSoftwareCompanyLogo;
use App\Support\InventoryImageManagementService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class RentalSoftwareCompanyLogoController extends Controller
{
    /**
     * Display a listing of rental software company logos.
     */
    public function index()
    {
        $logos = RentalSoftwareCompanyLogo::query()->ordered()->get();

        return view('admin.companies.rental-software-logos.index', compact('logos'));
    }

    /**
     * Show the form for creating a new logo.
     */
    public function create()
    {
        return view('admin.companies.rental-software-logos.create', [
            'defaultSortOrder' => RentalSoftwareCompanyLogo::nextSortOrder(),
            'usedSortOrders' => RentalSoftwareCompanyLogo::query()->pluck('sort_order')->all(),
        ]);
    }

    /**
     * Store a newly created logo in storage.
     */
    public function store(Request $request)
    {
        $validator = $this->makeLogoValidator($request);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $logoPath = InventoryImageManagementService::storeUploadedFile(
            $request->file('logo'),
            RentalSoftwareCompanyLogo::UPLOAD_DIR
        );

        RentalSoftwareCompanyLogo::create([
            'company_name' => trim($request->company_name),
            'logo_path' => $logoPath,
            'link' => $this->normalizeLink($request->input('link')),
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) $request->input('sort_order'),
        ]);

        return redirect()->route('admin.rental-software-company-logos.index')
            ->with('success', 'Rental software company logo created successfully.');
    }

    /**
     * Show the form for editing the specified logo.
     */
    public function edit(RentalSoftwareCompanyLogo $rentalSoftwareCompanyLogo)
    {
        return view('admin.companies.rental-software-logos.edit', [
            'logo' => $rentalSoftwareCompanyLogo,
            'usedSortOrders' => RentalSoftwareCompanyLogo::query()
                ->where('id', '!=', $rentalSoftwareCompanyLogo->id)
                ->pluck('sort_order')
                ->all(),
        ]);
    }

    /**
     * Update the specified logo in storage.
     */
    public function update(Request $request, RentalSoftwareCompanyLogo $rentalSoftwareCompanyLogo)
    {
        $validator = $this->makeLogoValidator($request, $rentalSoftwareCompanyLogo);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = [
            'company_name' => trim($request->company_name),
            'link' => $this->normalizeLink($request->input('link')),
            'is_active' => $request->boolean('is_active'),
            'sort_order' => (int) $request->input('sort_order'),
        ];

        if ($request->hasFile('logo')) {
            InventoryImageManagementService::deleteLocalFileIfStored($rentalSoftwareCompanyLogo->logo_path);
            $data['logo_path'] = InventoryImageManagementService::storeUploadedFile(
                $request->file('logo'),
                RentalSoftwareCompanyLogo::UPLOAD_DIR
            );
        }

        $rentalSoftwareCompanyLogo->update($data);

        return redirect()->route('admin.rental-software-company-logos.index')
            ->with('success', 'Rental software company logo updated successfully.');
    }

    /**
     * Remove the specified logo from storage.
     */
    public function destroy(RentalSoftwareCompanyLogo $rentalSoftwareCompanyLogo)
    {
        try {
            InventoryImageManagementService::deleteLocalFileIfStored($rentalSoftwareCompanyLogo->logo_path);
            $rentalSoftwareCompanyLogo->delete();

            return redirect()->route('admin.rental-software-company-logos.index')
                ->with('success', 'Rental software company logo deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.rental-software-company-logos.index')
                ->with('error', 'Cannot delete logo. ' . $e->getMessage());
        }
    }

    private function makeLogoValidator(Request $request, ?RentalSoftwareCompanyLogo $logo = null): ValidatorContract
    {
        $sortOrderRule = Rule::unique('rental_software_company_logos', 'sort_order');
        if ($logo !== null) {
            $sortOrderRule->ignore($logo->id);
        }

        $rules = [
            'company_name' => 'required|string|max:255',
            'link' => 'nullable|url|max:2048',
            'is_active' => 'boolean',
            'sort_order' => ['required', 'integer', 'min:1', 'max:999999', $sortOrderRule],
        ];

        if ($logo === null) {
            $rules['logo'] = 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048';
        } else {
            $rules['logo'] = 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048';
        }

        return Validator::make($request->all(), $rules, $this->validationMessages());
    }

    private function normalizeLink(?string $link): ?string
    {
        if ($link === null) {
            return null;
        }

        $trimmed = trim($link);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, string>
     */
    private function validationMessages(): array
    {
        return [
            'sort_order.required' => 'Please enter a sort order.',
            'sort_order.integer' => 'Sort order must be a whole number.',
            'sort_order.min' => 'Sort order must be at least 1.',
            'sort_order.max' => 'Sort order cannot exceed 999,999.',
            'sort_order.unique' => 'This sort order is already used by another logo. Please choose a unique value.',
        ];
    }
}
