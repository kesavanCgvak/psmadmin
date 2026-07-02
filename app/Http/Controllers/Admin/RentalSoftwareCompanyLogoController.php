<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RentalSoftwareCompanyLogo;
use App\Support\InventoryImageManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RentalSoftwareCompanyLogoController extends Controller
{
    /**
     * Display a listing of rental software company logos.
     */
    public function index()
    {
        $logos = RentalSoftwareCompanyLogo::orderBy('company_name')->get();

        return view('admin.companies.rental-software-logos.index', compact('logos'));
    }

    /**
     * Show the form for creating a new logo.
     */
    public function create()
    {
        return view('admin.companies.rental-software-logos.create');
    }

    /**
     * Store a newly created logo in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'is_active' => 'boolean',
        ]);

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
            'is_active' => $request->boolean('is_active'),
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
        ]);
    }

    /**
     * Update the specified logo in storage.
     */
    public function update(Request $request, RentalSoftwareCompanyLogo $rentalSoftwareCompanyLogo)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = [
            'company_name' => trim($request->company_name),
            'is_active' => $request->boolean('is_active'),
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
}
