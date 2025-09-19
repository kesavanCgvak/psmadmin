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
use Illuminate\Support\Facades\Storage;


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
                'date_format' => $company->date_format,
                'pricing_scheme' => $company->pricing_scheme,
                'rental_software' => $company->rental_software,
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
                'date_format' => 'nullable|string|max:20',
                'pricing_scheme' => 'nullable|string|max:50',
                'rental_software' => 'nullable|integer|exists:rental_softwares,id',
            ]);

            // Update only provided fields
            $company->update($validated);

            $preferences = [
                'currency_id' => $company->currency_id,
                'date_format' => $company->date_format,
                'pricing_scheme' => $company->pricing_scheme,
                'rental_software' => $company->rental_software,
            ];

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
     * Search companies with filters
     */
    public function searchCompanies(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            //Validate input
            $request->validate([
                'product_id' => 'required',
                'city_id' => 'required|integer|exists:cities,id',
                'country' => 'nullable|integer|exists:countries,id',
                'distance' => 'nullable|numeric|min:1',
            ]);

            //Get city lat/long from DB
            $city = DB::table('cities')->where('id', $request->city_id)->first();
            if (!$city || !$city->latitude || !$city->longitude) {
                return response()->json(['error' => 'City latitude and longitude are required'], 400);
            }

            $city_lat = $city->latitude;
            $city_lng = $city->longitude;

            // Support multiple product IDs
            $productIds = explode(',', $request->product_id);

            // Query without JSON aggregation
            $query = Company::with(['defaultContactProfile'])
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
                    'equipments.software_code'
                );

            // Optional filters
            if ($request->filled('country')) {
                $query->where('companies.country_id', $request->country);
            }

            $results = $query->get();

            if ($results->isEmpty()) {
                return response()->json(['message' => 'No companies found matching your filters.'], 200);
            }

            // Group results by company
            $companies = $results->groupBy('company_id')->map(function ($items) use ($city_lat, $city_lng, $request) {
                $first = $items->first();

                // Calculate distance
                $distance = 0;
                if ($first->company_lat && $first->company_lng) {
                    $distance = $this->calculateDistance($city_lat, $city_lng, $first->company_lat, $first->company_lng);
                }

                // Apply distance filter
                if ($request->filled('distance') && $distance > $request->distance) {
                    return null;
                }

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
                    'distance' => $distance,
                    'default_contact_profile' => $first->defaultContactProfile
                        ? [
                            'name' => $first->defaultContactProfile->full_name,
                            'email' => $first->defaultContactProfile->email,
                            'mobile' => $first->defaultContactProfile->mobile,
                        ]
                        : null,
                ];
            })->filter()->values();

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



}
