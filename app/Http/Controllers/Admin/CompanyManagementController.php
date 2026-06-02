<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Equipment;
use App\Models\Product;
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
use App\Services\InventoryImportService;
use App\Support\CompanyInventorySpecs;
use App\Support\InventoryImageSyncService;
use App\Support\InventoryProductSearch;

class CompanyManagementController extends Controller
{
    /**
     * Display a listing of the companies.
     */
    public function index()
    {
        $companies = Company::with(['region', 'country', 'city', 'currency', 'rentalSoftware'])
            ->withCount(['users', 'equipments'])
            ->orderByRaw('created_at IS NULL, created_at DESC')
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
            'is_open_api_enabled' => 'nullable|boolean',
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
        $isProviderCompany = strtolower((string) ($data['account_type'] ?? '')) === 'provider';
        $data['is_open_api_enabled'] = $isProviderCompany && $request->boolean('is_open_api_enabled');
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
        $company->load(['region', 'country', 'state', 'city', 'currency', 'rentalSoftware', 'users', 'defaultContact']);
        $company->loadCount('equipments');

        // Keep rating logic consistent with the companies index:
        // job_ratings (renter->provider) first, then company_ratings fallback.
        $jobRatings = DB::table('job_ratings')
            ->join('supply_jobs', 'job_ratings.supply_job_id', '=', 'supply_jobs.id')
            ->where('supply_jobs.provider_id', $company->id)
            ->whereNotNull('job_ratings.rated_at')
            ->select(
                DB::raw('AVG(job_ratings.rating) as avg_rating'),
                DB::raw('COUNT(job_ratings.id) as rating_count')
            )
            ->first();

        $companyRatings = DB::table('company_ratings')
            ->where('company_id', $company->id)
            ->select(
                DB::raw('AVG(rating) as avg_rating'),
                DB::raw('COUNT(id) as rating_count')
            )
            ->first();

        $userAvg = 0.0;
        $userCount = 0;

        if (!empty($jobRatings) && (int) ($jobRatings->rating_count ?? 0) > 0) {
            $userAvg = round((float) $jobRatings->avg_rating, 1);
            $userCount = (int) $jobRatings->rating_count;
        } elseif (!empty($companyRatings) && (int) ($companyRatings->rating_count ?? 0) > 0) {
            $userAvg = round((float) $companyRatings->avg_rating, 1);
            $userCount = (int) $companyRatings->rating_count;
        }

        $overrideRating = $company->rating_override;
        $displayRating = $overrideRating !== null ? (float) $overrideRating : $userAvg;

        return view('admin.companies.show', compact('company', 'userAvg', 'userCount', 'overrideRating', 'displayRating'));
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
            'is_open_api_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $oldAccountType = $company->account_type;
        $data = $request->all();
        $isProviderCompany = strtolower((string) ($request->input('account_type', $company->account_type))) === 'provider';
        $data['is_open_api_enabled'] = $isProviderCompany && $request->boolean('is_open_api_enabled');
        $company->update($data);

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

    /**
     * Server-side DataTables JSON for a company's marketplace inventory (company_inventory).
     */
    public function inventoryData(Request $request, Company $company)
    {
        try {
            $draw = (int) $request->get('draw', 1);
            $start = (int) $request->get('start', 0);
            $length = min((int) $request->get('length', 25), 100);
            $search = $request->get('search', []);
            $searchValue = is_array($search) && isset($search['value']) ? trim((string) $search['value']) : '';
            $order = $request->get('order', []);
            $orderColumn = isset($order[0]['column']) ? (int) $order[0]['column'] : 0;
            $orderDir = isset($order[0]['dir']) && strtolower((string) $order[0]['dir']) === 'desc' ? 'desc' : 'asc';

            $sortMap = [
                0 => 'company_inventory.id',
                1 => 'inventory_master.model',
                2 => 'brands.name',
                3 => 'inventory_master.psm_code',
                4 => 'company_inventory.quantity',
                5 => 'company_inventory.rental_price',
                6 => 'company_inventory.software_code',
            ];
            $orderBy = $sortMap[$orderColumn] ?? 'company_inventory.id';

            $base = Equipment::query()
                ->from('company_inventory')
                ->where('company_inventory.company_id', $company->id)
                ->join('inventory_master', 'company_inventory.product_id', '=', 'inventory_master.id')
                ->leftJoin('brands', 'inventory_master.brand_id', '=', 'brands.id')
                ->select('company_inventory.*');

            if ($searchValue !== '') {
                InventoryProductSearch::applyToCompanyInventoryJoinedQuery($base, $searchValue);
            }

            $totalRecords = Equipment::where('company_id', $company->id)->count();
            $filteredRecords = (clone $base)->count();

            $rowsQuery = clone $base;
            if ($searchValue !== '') {
                InventoryProductSearch::applyRelevanceOrderToCompanyInventoryJoinedQuery($rowsQuery, $searchValue);
            } else {
                $rowsQuery->orderBy($orderBy, $orderDir);
            }

            $rows = $rowsQuery->skip($start)
                ->take($length)
                ->get();

            $rows->load([
                'product.brand:id,name',
                'linearUnit:id,code',
                'weightUnit:id,code',
            ]);

            $data = [];
            foreach ($rows as $equipment) {
                $product = $equipment->product;
                $rental = $equipment->rental_price;
                $rentalDisplay = $rental === null ? '—' : '$' . number_format((float) $rental, 2);
                $dimensionsDisplay = CompanyInventorySpecs::formatDimensions($equipment) ?? '—';
                $weightDisplay = CompanyInventorySpecs::formatWeight($equipment) ?? '—';

                $removeUrl = route('admin.companies.inventory.destroy', [$company, $equipment]);

                $data[] = [
                    'id' => $equipment->id,
                    'product_id' => $product ? $product->id : null,
                    'model' => $product ? $product->model : '—',
                    'brand' => $product && $product->brand ? $product->brand->name : '—',
                    'psm_code' => $product && $product->psm_code ? $product->psm_code : '—',
                    'dimensions' => $dimensionsDisplay,
                    'weight' => $weightDisplay,
                    'quantity' => (int) $equipment->quantity,
                    'rental_price' => $rentalDisplay,
                    'software_code' => $equipment->software_code ?? '—',
                    'actions' => '<button type="button" class="btn btn-danger btn-sm btn-remove-inventory" data-url="' . e($removeUrl) . '" data-id="' . (int) $equipment->id . '" title="Remove from company"><i class="fas fa-times"></i></button>',
                ];
            }

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $totalRecords,
                'recordsFiltered' => $filteredRecords,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Company inventory DataTables: ' . $e->getMessage(), ['exception' => $e]);

            return response()->json([
                'draw' => (int) $request->get('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Could not load inventory.',
            ], 500);
        }
    }

    /**
     * Search inventory_master for products not yet linked to this company (for "Add product" UI).
     */
    public function searchInventoryMaster(Request $request, Company $company)
    {
        $search = trim((string) $request->get('search', ''));
        $excludeLinked = $request->boolean('exclude_linked', true);

        $linkedIds = [];
        if ($excludeLinked) {
            $linkedIds = Equipment::where('company_id', $company->id)->pluck('product_id')->all();
        }

        $query = Product::query()
            ->select(['id', 'model', 'psm_code', 'brand_id', 'category_id'])
            ->with(['brand:id,name', 'category:id,name']);

        if (!empty($linkedIds)) {
            $query->whereNotIn('id', $linkedIds);
        }

        if ($search !== '') {
            InventoryProductSearch::applyToProductQuery($query, $search, true);
            InventoryProductSearch::applyRelevanceOrderToProductQuery($query, $search);
        } else {
            $query->orderBy('model');
        }

        $products = $query->with(['linearUnit:id,code', 'weightUnit:id,code'])->limit(40)->get();

        $results = [];
        foreach ($products as $product) {
            $results[] = array_merge([
                'id' => $product->id,
                'model' => $product->model,
                'psm_code' => $product->psm_code ?? '—',
                'brand' => $product->brand ? $product->brand->name : '—',
                'category' => $product->category ? $product->category->name : '—',
            ], CompanyInventorySpecs::productSpecsForJson($product));
        }

        return response()->json($results);
    }

    /**
     * Link an inventory_master product to the company (company_inventory row).
     */
    public function storeInventory(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:inventory_master,id',
            'quantity' => 'nullable|integer|min:1',
            'rental_price' => 'nullable|numeric|min:0',
            'software_code' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $productId = (int) $request->input('product_id');
        if (InventoryImportService::findExistingInventoryForProduct($company->id, $productId)) {
            return response()->json([
                'success' => false,
                'message' => 'This product is already linked to the company.',
            ], 422);
        }

        $userId = $this->resolveInventoryUserId($company);
        if ($userId === null) {
            return response()->json([
                'success' => false,
                'message' => 'This company has no users. Add a user first, then link products.',
            ], 422);
        }

        $quantity = $request->input('quantity');
        if ($quantity === null || $quantity === '') {
            $quantity = 1;
        }

        $product = Product::findOrFail($productId);

        $equipment = Equipment::create(array_merge([
            'company_id' => $company->id,
            'user_id' => $userId,
            'product_id' => $productId,
            'quantity' => (int) $quantity,
            'rental_price' => $request->input('rental_price'),
            'software_code' => $request->input('software_code'),
        ], CompanyInventorySpecs::attributesFromProduct($product)));

        InventoryImageSyncService::syncMasterToEquipment($productId, (int) $equipment->id, true);

        return response()->json([
            'success' => true,
            'message' => 'Product added to company inventory.',
        ]);
    }

    /**
     * Remove a company_inventory row for this company.
     */
    public function destroyInventory(Company $company, Equipment $equipment)
    {
        if ((int) $equipment->company_id !== (int) $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid equipment record for this company.',
            ], 404);
        }

        try {
            foreach ($equipment->images as $image) {
                $imagePath = public_path($image->image_path);
                if (is_file($imagePath)) {
                    @unlink($imagePath);
                }
                $image->delete();
            }
            $equipment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product removed from company inventory.',
            ]);
        } catch (\Throwable $e) {
            \Log::error('destroyInventory: ' . $e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Could not remove inventory: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pick a company user to attribute new inventory rows (required by company_inventory.user_id).
     */
    private function resolveInventoryUserId(Company $company): ?int
    {
        if ($company->default_contact_id) {
            $u = User::where('company_id', $company->id)->where('id', $company->default_contact_id)->first();
            if ($u) {
                return (int) $u->id;
            }
        }

        $admin = User::where('company_id', $company->id)->where('is_admin', 1)->orderBy('id')->first();
        if ($admin) {
            return (int) $admin->id;
        }

        $any = User::where('company_id', $company->id)->orderBy('id')->first();

        return $any ? (int) $any->id : null;
    }
}

