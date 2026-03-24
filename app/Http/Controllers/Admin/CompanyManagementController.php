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
use App\Models\CompanyRating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        $companyIds = $companies->pluck('id')->values();

        // Same rating sources as API: job_ratings (renter→provider) then company_ratings fallback.
        $jobRatingsData = DB::table('job_ratings')
            ->join('supply_jobs', 'job_ratings.supply_job_id', '=', 'supply_jobs.id')
            ->whereIn('supply_jobs.provider_id', $companyIds)
            ->whereNotNull('job_ratings.rated_at')
            ->groupBy('supply_jobs.provider_id')
            ->select(
                'supply_jobs.provider_id',
                DB::raw('AVG(job_ratings.rating) as avg_rating'),
                DB::raw('COUNT(job_ratings.id) as rating_count')
            )
            ->get()
            ->keyBy('provider_id');

        $companyRatingsData = DB::table('company_ratings')
            ->whereIn('company_id', $companyIds)
            ->groupBy('company_id')
            ->select(
                'company_id',
                DB::raw('AVG(rating) as avg_rating'),
                DB::raw('COUNT(id) as rating_count')
            )
            ->get()
            ->keyBy('company_id');

        $ratingStats = [];
        foreach ($companyIds as $cid) {
            if (isset($jobRatingsData[$cid])) {
                $ratingStats[$cid] = [
                    'avg' => round((float) $jobRatingsData[$cid]->avg_rating, 1),
                    'count' => (int) $jobRatingsData[$cid]->rating_count,
                    'source' => 'job_ratings',
                ];
            } elseif (isset($companyRatingsData[$cid])) {
                $ratingStats[$cid] = [
                    'avg' => round((float) $companyRatingsData[$cid]->avg_rating, 1),
                    'count' => (int) $companyRatingsData[$cid]->rating_count,
                    'source' => 'company_ratings',
                ];
            } else {
                $ratingStats[$cid] = [
                    'avg' => 0.0,
                    'count' => 0,
                    'source' => null,
                ];
            }
        }

        return view('admin.companies.index', compact('companies', 'ratingStats'));
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

        // For provider companies, create an initial 5-star company rating
        // using the currently logged-in admin user's ID.
        if (strtolower((string) $company->account_type) === 'provider' && Auth::id()) {
            CompanyRating::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'user_id' => Auth::id(),
                ],
                [
                    'rating' => 5,
                ]
            );
        }

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
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $oldAccountType = $company->account_type;
        $company->update($request->all());

        // Sync users.account_type when company account_type changes
        if ($request->has('account_type') && $oldAccountType !== $company->account_type) {
            User::where('company_id', $company->id)->update(['account_type' => $company->account_type]);
        }

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
     * Update the admin override rating for a company (absolute value).
     * NULL clears the override (falls back to calculated rating).
     */
    public function updateRatingOverride(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'rating_override' => 'nullable|numeric|min:0|max:5',
            'rating_override_reason' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $override = $request->input('rating_override');
        $override = ($override === '' || $override === null) ? null : (float) $override;

        $company->update([
            'rating_override' => $override,
            'rating_override_reason' => $request->input('rating_override_reason') ?: null,
            'rating_override_set_by' => Auth::id(),
            'rating_override_set_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Company rating override updated.');
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

