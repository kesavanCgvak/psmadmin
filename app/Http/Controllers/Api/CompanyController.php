<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use App\Models\Company;
use App\Models\User;
use App\Models\CompanyRating;
use App\Models\CompanyBlock;
use App\Models\CompanyProviderBlock;



class CompanyController extends Controller
{
    /**
     * Get company info
     */
    public function getInfo()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found'
                ], 404);
            }

            $companyDetails = [
                'name' => $company->name,
                'description' => $company->description,
                'logo' => $company->logo,
                'image1' => $company->image1,
                'image2' => $company->image2,
                'image3' => $company->image3,
            ];

            return response()->json([
                'success' => true,
                'data' => $companyDetails
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching company info', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch company info'
            ], 500);
        }
    }

    /**
     * Update company basic info
     */
    public function updateInfo(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            if (!$company) {
                return response()->json(['success' => false, 'message' => 'Company not found'], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'website' => 'sometimes|url',
                'phone' => 'sometimes|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $company->update($request->only(['name', 'website', 'phone']));

            return response()->json(['success' => true, 'message' => 'Company info updated successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Error updating company info', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Unable to update company info'], 500);
        }
    }

    /**
     * Get company preferences
     */
    public function getPreferences()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found',
                ], 404);
            }
            $preferences = [
                'currency_id' => $company->currency_id,
                'date_format_id' => $company->date_format_id,
                'pricing_scheme_id' => $company->pricing_scheme_id,
                'hide_from_gear_finder' => $company->hide_from_gear_finder,
                'rental_software_id' => $company->rental_software_id,
            ];

            return response()->json([
                'success' => true,
                'preferences' => $preferences,
            ], 200);

        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token has expired'
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token'
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token not provided'
            ], 401);
        } catch (\Exception $e) {
            Log::error('Error fetching preferences: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch preferences',
            ], 500);
        }
    }

    /**
     * Update company preferences
     */
    public function updatePreferences(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found',
                ], 404);
            }

            // Validate payload
            $validated = $request->validate([
                'currency_id' => 'nullable|integer|exists:currencies,id',
                'date_format_id' => 'nullable|integer|exists:date_formats,id',
                'pricing_scheme_id' => 'nullable|integer|exists:pricing_schemes,id',
                'rental_software_id' => 'nullable|integer|exists:rental_softwares,id',
            ]);

            // Update only provided fields
            $company->update($validated);

            // Reload relationships
            $company->load(['currency', 'rentalSoftware', 'dateFormat', 'pricingScheme']);

            // Format response with full objects
            $preferences = [];

            // Currency object
            if ($company->currency) {
                $preferences['currency'] = [
                    'id' => $company->currency->id,
                    'name' => $company->currency->name,
                    'code' => $company->currency->code,
                    'symbol' => $company->currency->symbol,
                ];
            }

            // Rental Software object
            if ($company->rentalSoftware) {
                $preferences['rental_software'] = [
                    'id' => $company->rentalSoftware->id,
                    'name' => $company->rentalSoftware->name,
                    'version' => $company->rentalSoftware->version ?? '',
                ];
            }

            // Date Format string
            if ($company->dateFormat) {
                $preferences['date_format'] = $company->dateFormat->format;
            }

            // Pricing Scheme string (using name)
            if ($company->pricingScheme) {
                $preferences['pricing_scheme'] = $company->pricingScheme->name;
            }

            return response()->json([
                'success' => true,
                'message' => 'Preferences updated successfully.',
                'preferences' => $preferences,
            ], 200);

        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token has expired'
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token'
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token not provided'
            ], 401);
        } catch (\Exception $e) {
            Log::error('Error updating preferences: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Unable to update preferences',
            ], 500);
        }
    }


    /**
     * Update default contact
     */
    public function updateDefaultContact(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            $validator = Validator::make($request->all(), [
                'contact_id' => 'required|exists:users,id'
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            //Update default contact id in companies table
            $company->default_contact_id = $request->contact_id;
            $company->save();

            //Reset all company users' default flag to 0
            User::where('company_id', $company->id)
                ->update(['is_company_default_contact' => 0]);

            //Set the chosen user as default contact
            User::where('id', $request->contact_id)
                ->update(['is_company_default_contact' => 1]);

            return response()->json([
                'success' => true,
                'message' => 'Default contact updated successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error updating default contact', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to update default contact'
            ], 500);
        }
    }

    /**
     * Update Gear Finder visibility
     */
    public function updateGearFinderVisibility(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            $validated = $request->validate([
                'hide_from_gear_finder' => 'required|boolean',
            ]);

            $company->hide_from_gear_finder = $validated['hide_from_gear_finder'];
            $company->save();

            $message = $validated['hide_from_gear_finder']
                ? 'Company hidden from Gear Finder.'
                : 'Company visible in Gear Finder.';

            return response()->json([
                'success' => true,
                'message' => $message,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating Gear Finder visibility', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to update Gear Finder visibility',
            ], 500);
        }
    }

    /**
     * Get default contact
     */
    public function getDefaultContact()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            $contact = $company->defaultContactProfile ?? null;

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Default contact not found'
                ], 404);
            }

            $contactData = [
                'name' => $contact->full_name,
                'email' => $contact->email,
                'mobile' => $contact->mobile,
            ];

            return response()->json([
                'success' => true,
                'contact' => $contactData
            ], 200);

        } catch (TokenExpiredException $e) {
            return response()->json(['success' => false, 'message' => 'Token has expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['success' => false, 'message' => 'Invalid token'], 401);
        } catch (JWTException $e) {
            return response()->json(['success' => false, 'message' => 'Token not provided'], 401);
        } catch (\Exception $e) {
            Log::error('Error fetching default contact', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Unable to fetch default contact'], 500);
        }
    }

    /**
     * Update company extra info
     */
    public function updateCompanyInfo(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            $company->update($request->all());

            return response()->json(['success' => true, 'message' => 'Company info updated'], 200);
        } catch (\Exception $e) {
            Log::error('Error updating company info', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Unable to update company info'], 500);
        }
    }

    /**
     * Upload company image (logo or image1/image2/image3)
     * Auto-deletes old image if exists
     */
    public function uploadImage(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            $request->validate([
                'type' => 'required|in:logo,image1,image2,image3',
                'image' => 'required|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            // Define upload folder
            $uploadPath = public_path('images/company_images');

            // Ensure folder exists
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            // Delete old image if exists
            if ($company->{$request->type}) {
                $oldImage = public_path($company->{$request->type});
                if (file_exists($oldImage)) {
                    unlink($oldImage);
                }
            }

            // Generate unique filename
            $filename = time() . '_' . uniqid() . '.' . $request->file('image')->getClientOriginalExtension();

            // Move uploaded file
            $request->file('image')->move($uploadPath, $filename);

            // Save relative path in DB
            $relativePath = 'images/company_images/' . $filename;

            $company->update([
                $request->type => $relativePath
            ]);

            return response()->json([
                'success' => true,
                'message' => ucfirst($request->type) . ' uploaded successfully',
                'path' => $relativePath,
                'url' => url($relativePath)
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error uploading company image', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to upload company image'
            ], 500);
        }
    }

    /**
     * Get company images (logo + 3 images)
     */
    public function getImages()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            $images = [
                'logo' => $company->logo ? [
                    'path' => $company->logo,
                    'url' => url($company->logo)
                ] : null,
                'image1' => $company->image1 ? [
                    'path' => $company->image1,
                    'url' => url($company->image1)
                ] : null,
                'image2' => $company->image2 ? [
                    'path' => $company->image2,
                    'url' => url($company->image2)
                ] : null,
                'image3' => $company->image3 ? [
                    'path' => $company->image3,
                    'url' => url($company->image3)
                ] : null,
            ];

            return response()->json([
                'success' => true,
                'images' => $images
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching company images', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch company images'
            ], 500);
        }
    }

    /**
     * Delete company image (logo or image1/image2/image3)
     * Auto-deletes file from public/images/company_images
     */
    public function deleteImage(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            $request->validate([
                'type' => 'required|in:logo,image1,image2,image3'
            ]);

            $type = $request->type;

            if ($company->{$type}) {
                $filePath = public_path($company->{$type});

                // Delete file if exists
                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                // Remove reference from DB
                $company->update([$type => null]);
            }

            return response()->json([
                'success' => true,
                'message' => ucfirst($type) . ' deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error deleting company image', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to delete company image'
            ], 500);
        }
    }


    /**
     * Get company address
     */
    public function getAddress()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found'
                ], 404);
            }

            $address = [
                'region' => $company->region_id,
                'country' => $company->country_id,
                'city' => $company->city_id,
                'state' => $company->state_id,
                'address_line_1' => $company->address_line_1,
                'address_line_2' => $company->address_line_2,
                'postal_code' => $company->postal_code,
            ];

            return response()->json([
                'success' => true,
                'address' => $address
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching address', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch address'
            ], 500);
        }
    }

    /**
     * Update company address
     */
    public function updateAddress(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                // 'region' => 'nullable|integer|exists:regions,id',
                'country_id' => 'nullable|integer|exists:countries,id',
                'city_id' => 'nullable|integer|exists:cities,id',
                'state_id' => 'nullable|integer|exists:states_provinces,id',
                'address_line_1' => 'nullable|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $company->update([
                // 'region_id' => $request->region,
                'country_id' => $request->country_id,
                'city_id' => $request->city_id,
                'state_id' => $request->state_id,
                'address_line_1' => $request->address_line_1,
                'address_line_2' => $request->address_line_2,
                'postal_code' => $request->postal_code,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Address updated successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error updating address', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to update address'
            ], 500);
        }
    }

    /**
     * Get search priority
     */
    public function getSearchPriority()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'search_priority' => $company->search_priority
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching search priority', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch search priority'
            ], 500);
        }
    }

    /**
     * Update search priority
     */
    public function updateSearchPriority(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'search_priority' => 'required|integer|min:1|max:9999'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $company->update([
                'search_priority' => $request->search_priority
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Search priority updated successfully',
                'search_priority' => $company->search_priority
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error updating search priority', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to update search priority'
            ], 500);
        }
    }

    /**
     * Search for companies offering specific products near a city.
     * Supports multiple products, includes distance, available quantity, and currency.
     */
    public function searchCompanies(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // ✅ Validate request
            $validated = $request->validate([
                'products' => 'required|array|min:1',
                'products.*.product_id' => 'required|integer|exists:products,id',
                'products.*.quantity' => 'required|numeric|min:1',
                'city_id' => 'required|integer|exists:cities,id',
                'country' => 'nullable|integer|exists:countries,id',
                'distance' => 'nullable|numeric|min:1',
            ]);

            // ✅ Get city coordinates
            $city = DB::table('cities')->where('id', $validated['city_id'])->first();
            if (!$city || !$city->latitude || !$city->longitude) {
                return response()->json(['error' => 'City latitude and longitude are required'], 400);
            }

            $cityLat = $city->latitude;
            $cityLng = $city->longitude;
            $radius = $validated['distance'] ?? 50; // default 50 km

            // ✅ Extract product IDs & quantities
            $productData = collect($validated['products']);
            $productIds = $productData->pluck('product_id')->toArray();
            $requestedQuantities = $productData->pluck('quantity', 'product_id')->toArray();

            // ✅ Blocked companies for the current user
            $blockedCompanyIds = DB::table('company_blocks')
                ->where('user_id', $user->id)
                ->pluck('company_id')
                ->toArray();

            // ✅ Main query with joins + geolocation (exclude admin-blocked companies)
            $query = Company::with(['defaultContactProfile'])
                ->whereNull('blocked_by_admin_at')
                ->join('equipments', function ($join) use ($productIds) {
                    $join->on('companies.id', '=', 'equipments.company_id')
                        ->whereIn('equipments.product_id', $productIds);
                })
                ->join('products', 'products.id', '=', 'equipments.product_id')
                ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                ->join('currencies', 'currencies.id', '=', 'companies.currency_id')
                ->leftJoin('rental_softwares', 'rental_softwares.id', '=', 'companies.rental_software_id')
                // 🆕 Join city, state, and country tables for geolocation details
                ->leftJoin('cities', 'cities.id', '=', 'companies.city_id')
                ->leftJoin('states_provinces', 'states_provinces.id', '=', 'companies.state_id')
                ->leftJoin('countries', 'countries.id', '=', 'companies.country_id')
                ->select(
                    'companies.id as company_id',
                    'companies.name as company_name',
                    'companies.logo as company_logo',
                    'companies.latitude as company_lat',
                    'companies.longitude as company_lng',
                    'companies.rating as company_rating',
                    'companies.default_contact_id',
                    'companies.city_id',
                    'equipments.product_id',
                    'equipments.quantity',
                    // 'products.model as product_name',
                    DB::raw("CONCAT(COALESCE(brands.name, ''),
                        CASE WHEN brands.name IS NOT NULL THEN ' - ' ELSE '' END,
                        products.model) as product_name"),
                    'products.psm_code',
                    'equipments.price',
                    'equipments.software_code',
                    'currencies.id as currency_id',
                    'currencies.name as currency_name',
                    'currencies.code as currency_code',
                    'currencies.symbol as currency_symbol',
                    'rental_softwares.name as rental_software_code',
                    // 🆕 Geolocation fields
                    'cities.name as city_name',
                    'states_provinces.name as state_name',
                    'countries.name as country_name',
                    DB::raw("(
                    COALESCE(
                        6371 * acos(
                            LEAST(
                                1.0,
                                cos(radians($cityLat))
                                * cos(radians(companies.latitude))
                                * cos(radians(companies.longitude) - radians($cityLng))
                                + sin(radians($cityLat))
                                * sin(radians(companies.latitude))
                            )
                        ), 0
                    )
                ) as distance")
                )
                ->where('companies.id', '!=', $user->company_id)
                ->where('companies.hide_from_gear_finder', 0);

            // ✅ Exclude blocked companies
            if (!empty($blockedCompanyIds)) {
                $query->whereNotIn('companies.id', $blockedCompanyIds);
            }

            // ✅ Country filter
            if (!empty($validated['country'])) {
                $query->where('companies.country_id', $validated['country']);
            }

            // ✅ Distance or same-city fallback
            $query->havingRaw('distance <= ? OR companies.city_id = ?', [$radius, $validated['city_id']])
                ->orderBy('distance');

            $results = $query->get();

            if ($results->isEmpty()) {
                return response()->json(['message' => 'No companies found matching your filters.'], 200);
            }

            // ✅ Group results by company
            $companies = $results->groupBy('company_id')->map(function ($items) use ($requestedQuantities) {
                $first = $items->first();

                return [
                    'id' => $first->company_id,
                    'name' => $first->company_name,
                    'company_logo' => $first->company_logo,
                    'rating' => $first->company_rating,
                    'rental_software_code' => $first->rental_software_code,
                    'distance' => round($first->distance, 2),
                    'location' => [ // 🆕 Added location block
                        'country' => $first->country_name,
                        'state' => $first->state_name,
                        'city' => $first->city_name,
                    ],
                    'currency' => [
                        'id' => $first->currency_id,
                        'name' => $first->currency_name,
                        'code' => $first->currency_code,
                        'symbol' => $first->currency_symbol,
                    ],
                    'products' => $items->map(function ($item) use ($requestedQuantities) {
                        $requestedQty = $requestedQuantities[$item->product_id] ?? 0;
                        $availableQty = $item->quantity ?? 0;

                        return [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product_name,
                            'requested_quantity' => (int) $requestedQty,
                            'available_quantity' => (int) $availableQty,
                            'price' => number_format($item->price, 2, '.', ''),
                            'software_code' => $item->software_code,
                            'psm_code' => $item->psm_code,
                        ];
                    })->values(),
                    'default_contact_profile' => $first->defaultContactProfile
                        ? [
                            'name' => $first->defaultContactProfile->full_name,
                            'email' => $first->defaultContactProfile->email,
                            'mobile' => $first->defaultContactProfile->mobile,
                        ]
                        : null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $companies
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error searching companies: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        }
    }

    /**
     * Search companies with filters and distance radius
     */
    public function searchCompaniesOld(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // Validate input
            $request->validate([
                'product_id' => 'required',
                'city_id' => 'required|integer|exists:cities,id',
                'country' => 'nullable|integer|exists:countries,id',
                'distance' => 'nullable|numeric|min:1',
            ]);

            // Get city lat/long from DB
            $city = DB::table('cities')->where('id', $request->city_id)->first();
            if (!$city || !$city->latitude || !$city->longitude) {
                return response()->json(['error' => 'City latitude and longitude are required'], 400);
            }

            $city_lat = $city->latitude;
            $city_lng = $city->longitude;
            $radius = $request->distance ?? 50; // default to 50 km if not given

            // Support multiple product IDs
            $productIds = explode(',', $request->product_id);

            // Main query with SQL-based distance calculation (exclude admin-blocked companies)
            $query = Company::where('hide_from_gear_finder', 0)
                ->whereNull('blocked_by_admin_at')
                ->with(['defaultContactProfile'])
                ->join('equipments', function ($join) use ($productIds) {
                    $join->on('companies.id', '=', 'equipments.company_id')
                        ->whereIn('equipments.product_id', $productIds);
                })
                ->join('products', 'products.id', '=', 'equipments.product_id')
                ->select(
                    'companies.id as company_id',
                    'companies.name as company_name',
                    'companies.latitude as company_lat',
                    'companies.longitude as company_lng',
                    'companies.rating as company_rating',
                    'companies.default_contact_id',
                    'equipments.product_id',
                    'products.model as product_name',
                    'equipments.price',
                    'equipments.software_code',
                    DB::raw("(
                    6371 * acos(
                        cos(radians($city_lat))
                        * cos(radians(companies.latitude))
                        * cos(radians(companies.longitude) - radians($city_lng))
                        + sin(radians($city_lat))
                        * sin(radians(companies.latitude))
                    )
                ) as distance")
                )
                ->having('distance', '<=', $radius)
                ->orderBy('distance');

            if ($request->filled('country')) {
                $query->where('companies.country_id', $request->country);
            }

            $results = $query->get();

            if ($results->isEmpty()) {
                return response()->json(['message' => 'No companies found matching your filters.'], 200);
            }

            // Group results by company
            $companies = $results->groupBy('company_id')->map(function ($items) {
                $first = $items->first();

                return [
                    'id' => $first->company_id,
                    'name' => $first->company_name,
                    'rating' => $first->company_rating,
                    'products' => $items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product_name,
                            'price' => number_format($item->price, 2, '.', ''),
                            'software_code' => $item->software_code,
                        ];
                    })->values(),
                    'distance' => round($first->distance, 2), // from SQL
                    'default_contact_profile' => $first->defaultContactProfile
                        ? [
                            'name' => $first->defaultContactProfile->full_name,
                            'email' => $first->defaultContactProfile->email,
                            'mobile' => $first->defaultContactProfile->mobile,
                        ]
                        : null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $companies
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error searching companies for product: ' . $e->getMessage(), [
                'error' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Something went wrong. Please try again later.'], 500);
        }
    }

    // Haversine Distance Formula
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * List all companies except logged-in user's company.
     */
    public function listCompanies()
    {
        try {
            // 1️⃣ Authenticate user
            if (!$user = JWTAuth::parseToken()->authenticate()) {
                Log::warning('Unauthorized access attempt in listCompanies()');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized user'
                ], 401);
            }

            // 2️⃣ Get all companies except user's company (exclude admin-blocked)
            $companies = Company::with(['country', 'state', 'city'])
                ->withAvg('ratings', 'rating')
                ->where('id', '!=', $user->company_id)
                ->whereNull('blocked_by_admin_at')
                ->get();

            // 3️⃣ Fetch all ratings for these companies (single query)
            $companyIds = $companies->pluck('id');

            $userRatings = CompanyRating::whereIn('company_id', $companyIds)
                ->where('user_id', $user->id)
                ->pluck('rating', 'company_id'); // key = company_id, value = rating

            // 4️⃣ Fetch all blocked companies (single query)
            $blockedCompanies = CompanyBlock::whereIn('company_id', $companyIds)
                ->where('user_id', $user->id)
                ->pluck('company_id')
                ->toArray();

            // 5️⃣ Final response map (no queries inside)
            $formatted = $companies->map(function ($company) use ($userRatings, $blockedCompanies) {
                // Calculate average rating: use company_ratings average if available, otherwise fall back to companies.rating
                $avgRating = null;
                if ($company->ratings_avg_rating !== null) {
                    // Company has ratings in company_ratings table, use the average
                    $avgRating = $company->ratings_avg_rating;
                } else {
                    // No ratings in company_ratings table, fall back to default rating from companies.rating
                    $avgRating = $company->rating ?? 0;
                }

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'company_logo' => $company->logo ?? null,

                    // Location fields
                    'city' => $company->city?->name ?? null,
                    'state' => $company->state?->name ?? null,
                    'country' => $company->country?->name ?? null,

                    // Ratings: use company_ratings average if available, otherwise fall back to companies.rating
                    'average_rating' => round($avgRating, 1),
                    'user_rating' => $userRatings[$company->id] ?? null,

                    // Block status
                    'is_blocked' => in_array($company->id, $blockedCompanies),
                ];
            });

            return response()->json([
                'success' => true,
                'companies' => $formatted
            ], 200);

        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            Log::error('Invalid token in listCompanies()', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid authentication token'
            ], 401);

        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            Log::error('Expired token in listCompanies()', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Token has expired'
            ], 401);

        } catch (\Exception $e) {
            Log::error('Error fetching companies list', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch companies. Please try again later.'
            ], 500);
        }
    }

    /**
     * Add or update rating for a company.
     */
    public function rateCompany(Request $request, $companyId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if ($user->company_id == $companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot rate your own company'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'rating' => 'required|integer|min:1|max:5'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            CompanyRating::updateOrCreate(
                ['company_id' => $companyId, 'user_id' => $user->id],
                ['rating' => $request->rating]
            );

            // Update consolidated rating in companies table
            $avgRating = CompanyRating::where('company_id', $companyId)->avg('rating');
            Company::where('id', $companyId)->update(['rating' => $avgRating]);

            return response()->json([
                'success' => true,
                'message' => 'Rating updated successfully',
                'average_rating' => round($avgRating, 1)
            ]);

        } catch (\Exception $e) {
            Log::error('Error rating company', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to update rating'
            ], 500);
        }
    }

    /**
     * Block a company for the logged-in user.
     */
    public function blockCompany(Request $request, $companyId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if ($user->company_id == $companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot block your own company'
                ], 403);
            }

            $block = $request->input('block', true); // default true if not passed

            if ($block) {
                // Block company
                CompanyBlock::firstOrCreate([
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Company blocked successfully',
                    'data' => [
                        'company_id' => $companyId,
                        'is_blocked' => true
                    ]
                ], 200);

            } else {
                // Unblock company
                CompanyBlock::where('company_id', $companyId)
                    ->where('user_id', $user->id)
                    ->delete();

                return response()->json([
                    'success' => true,
                    'message' => 'Company unblocked successfully',
                    'data' => [
                        'company_id' => $companyId,
                        'is_blocked' => false
                    ]
                ], 200);
            }

        } catch (\Exception $e) {
            Log::error('Error blocking/unblocking company', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to update block status'
            ], 500);
        }
    }

    /**
     * Unblock a company for the logged-in user.
     */
    public function unblockCompany($companyId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            CompanyBlock::where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Company unblocked successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error unblocking company', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to unblock company'
            ], 500);
        }
    }

    /**
     * Block or unblock a provider company for the logged-in user's company.
     */
    public function toggleProviderBlock(Request $request, $providerId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $companyId = $user->company_id;

            // Safety: prevent blocking own company
            if ($companyId == $providerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot block your own company'
                ], 403);
            }

            $block = $request->boolean('block', true);

            if ($block) {
                // Block provider for company
                CompanyProviderBlock::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'provider_id' => $providerId,
                    ],
                    [
                        'blocked_by_user_id' => $user->id,
                        'blocked_at' => now(),
                    ]
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Provider blocked successfully',
                    'data' => [
                        'provider_id' => $providerId,
                        'is_blocked' => true
                    ]
                ], 200);
            }

            // Unblock provider
            CompanyProviderBlock::where('company_id', $companyId)
                ->where('provider_id', $providerId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Provider unblocked successfully',
                'data' => [
                    'provider_id' => $providerId,
                    'is_blocked' => false
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Provider block toggle failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'provider_id' => $providerId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update provider block status'
            ], 500);
        }
    }


}
