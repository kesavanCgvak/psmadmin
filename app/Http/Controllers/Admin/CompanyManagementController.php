<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Currency;
use App\Models\RentalSoftware;
use App\Models\DateFormat;
use App\Models\PricingScheme;
use App\Models\Region;
use App\Models\Country;
use App\Models\StateProvince;
use App\Models\City;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompanyManagementController extends Controller
{
    /**
     * Display a listing of the companies.
     */
    public function index()
    {
        $companies = Company::with(['region', 'country', 'city', 'currency', 'rentalSoftware'])
            ->withCount(['users', 'equipments'])
            ->get();
        return view('admin.companies.index', compact('companies'));
    }

    /**
     * Show the form for creating a new company.
     */
    public function create(Request $request)
    {
        $regions = Region::orderBy('name')->get();
        // Countries, states, and cities will be loaded dynamically via AJAX
        $countries = collect(); // Empty collection
        $states = collect(); // Empty collection
        $cities = collect(); // Empty collection
        $currencies = Currency::orderBy('name')->get();
        $rentalSoftwares = RentalSoftware::orderBy('name')->get();
        $dateFormats = DateFormat::orderBy('name')->get();
        $pricingSchemes = PricingScheme::orderBy('name')->get();
        $returnToUserCreate = $request->query('return_to_user_create', false);

        return view('admin.companies.create', compact('regions', 'countries', 'states', 'cities', 'currencies', 'rentalSoftwares', 'dateFormats', 'pricingSchemes', 'returnToUserCreate'));
    }

    /**
     * Store a newly created company in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:companies,name',
            'account_type' => 'required|in:user,provider',
            'description' => 'nullable|string',
            'region_id' => 'nullable|exists:regions,id',
            'country_id' => 'nullable|exists:countries,id',
            'state_id' => 'nullable|exists:states_provinces,id',
            'city_id' => 'nullable|exists:cities,id',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'currency_id' => 'nullable|exists:currencies,id',
            'rental_software_id' => 'nullable|exists:rental_softwares,id',
            'date_format' => 'nullable|string|max:255',
            'date_format_id' => 'nullable|exists:date_formats,id',
            'pricing_scheme' => 'nullable|string|max:255',
            'pricing_scheme_id' => 'nullable|exists:pricing_schemes,id',
            'subscription_mode' => 'nullable|in:free,paid',
        ], [
            'account_type.required' => 'Company type is required.',
            'account_type.in' => 'Company type must be either User or Provider.',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $data = $request->all();
        // Set default subscription_mode to 'paid' if not provided
        if (!isset($data['subscription_mode'])) {
            $data['subscription_mode'] = 'paid';
        }
        $company = Company::create($data);

        // Check if we should redirect back to user create page
        if ($request->input('return_to_user_create')) {
            return redirect()->route('admin.users.create', ['company_id' => $company->id])
                ->with('success', 'Company created successfully. Please continue with user creation.');
        }

        return redirect()->route('admin.companies.index')
            ->with('success', 'Company created successfully.');
    }

    /**
     * Display the specified company.
     */
    public function show(Company $company)
    {
        $company->load(['region', 'country', 'state', 'city', 'currency', 'rentalSoftware', 'users', 'equipments.product.brand', 'defaultContact']);
        return view('admin.companies.show', compact('company'));
    }

    /**
     * Show the form for editing the specified company.
     */
    public function edit(Company $company)
    {
        $regions = Region::orderBy('name')->get();
        // Countries, states, and cities will be loaded dynamically via AJAX based on existing values
        $countries = Country::where('region_id', $company->region_id)->orderBy('name')->get();
        $states = StateProvince::where('country_id', $company->country_id)->orderBy('name')->get();
        $cities = City::where('state_id', $company->state_id)->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $rentalSoftwares = RentalSoftware::orderBy('name')->get();
        $dateFormats = DateFormat::orderBy('name')->get();
        $pricingSchemes = PricingScheme::orderBy('name')->get();

        return view('admin.companies.edit', compact('company', 'regions', 'countries', 'states', 'cities', 'currencies', 'rentalSoftwares', 'dateFormats', 'pricingSchemes'));
    }

    /**
     * Update the specified company in storage.
     */
    public function update(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:companies,name,' . $company->id,
            'description' => 'nullable|string',
            'region_id' => 'nullable|exists:regions,id',
            'country_id' => 'nullable|exists:countries,id',
            'state_id' => 'nullable|exists:states_provinces,id',
            'city_id' => 'nullable|exists:cities,id',
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'currency_id' => 'nullable|exists:currencies,id',
            'rental_software_id' => 'nullable|exists:rental_softwares,id',
            'date_format' => 'nullable|string|max:255',
            'date_format_id' => 'nullable|exists:date_formats,id',
            'pricing_scheme' => 'nullable|string|max:255',
            'pricing_scheme_id' => 'nullable|exists:pricing_schemes,id',
            'subscription_mode' => 'nullable|in:free,paid',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $company->update($request->all());

        // Preserve filter parameters from the request if they exist
        $filterParams = $request->only(['country', 'city', 'state', 'region', 'search', 'page']);
        $redirectUrl = route('admin.companies.index');

        if (!empty(array_filter($filterParams))) {
            $redirectUrl .= '?' . http_build_query(array_filter($filterParams));
        }

        return redirect($redirectUrl)
            ->with('success', 'Company updated successfully.');
    }

    /**
     * Remove the specified company from storage.
     */
    public function destroy(Company $company)
    {
        // Relation checks before deletion
        if ($company->users()->exists()) {
            return redirect()->route('admin.companies.index')
                ->with('error', 'Cannot delete — this company has users.');
        }
        if ($company->equipments()->exists()) {
            return redirect()->route('admin.companies.index')
                ->with('error', 'Cannot delete — this company has equipment.');
        }

        try {
            $company->delete();
            return redirect()->route('admin.companies.index')
                ->with('success', 'Company deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.companies.index')
                ->with('error', 'Cannot delete company. ' . $e->getMessage());
        }
    }

    /**
     * Bulk delete multiple companies.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'company_ids' => 'required|array',
            'company_ids.*' => 'exists:companies,id'
        ]);

        $companyIds = $request->company_ids;
        $deletedCount = 0;
        $errors = [];

        foreach ($companyIds as $companyId) {
            $company = Company::find($companyId);

            if (!$company) {
                continue;
            }

            try {
                // Company deletion will cascade delete users and equipment
                $company->delete();
                $deletedCount++;
            } catch (\Exception $e) {
                $errors[] = "Failed to delete company: {$company->name} - " . $e->getMessage();
            }
        }

        if ($deletedCount > 0) {
            $message = "Successfully deleted {$deletedCount} company/companies.";
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

            return redirect()->route('admin.companies.index')
                ->with('success', $message);
        } else {
            $message = 'No companies were deleted. ' . (!empty($errors) ? implode(', ', $errors) : '');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'deleted_count' => 0,
                    'errors' => $errors
                ]);
            }

            return redirect()->route('admin.companies.index')
                ->with('error', $message);
        }
    }

    /**
     * Get countries by region (AJAX endpoint)
     */
    public function getCountriesByRegion($regionId)
    {
        $countries = Country::where('region_id', $regionId)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code']);

        return response()->json($countries);
    }

    /**
     * Get states by country (AJAX endpoint)
     */
    public function getStatesByCountry($countryId)
    {
        $states = StateProvince::where('country_id', $countryId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($states);
    }

    /**
     * Get cities by state (AJAX endpoint)
     */
    public function getCitiesByState($stateId)
    {
        $cities = City::where('state_id', $stateId)
            ->orderBy('name')
            ->get(['id', 'name', 'latitude', 'longitude']);

        return response()->json($cities);
    }

    /**
     * Get city coordinates (AJAX endpoint)
     */
    public function getCityCoordinates($cityId)
    {
        $city = City::find($cityId);

        if (!$city) {
            return response()->json(['error' => 'City not found'], 404);
        }

        return response()->json([
            'latitude' => $city->latitude,
            'longitude' => $city->longitude
        ]);
    }
}

