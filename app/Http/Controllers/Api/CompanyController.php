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

            $company->default_contact_id = $request->contact_id;
            $company->save();

            return response()->json(['success' => true, 'message' => 'Default contact updated successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Error updating default contact', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Unable to update default contact'], 500);
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

            $contact = $company->defaultContact ?? null;

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Default contact not found'
                ], 404);
            }

            $contactData = [
                'name' => $contact->name,
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
     * Get company images (logo + 3 images)
     */
    public function getImages()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $company = $user->company;

            $images = [
                'logo' => $company->logo,
                'image1' => $company->image1,
                'image2' => $company->image2,
                'image3' => $company->image3,
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

            // Delete old image if exists
            if ($company->{$request->type} && Storage::disk('public')->exists($company->{$request->type})) {
                Storage::disk('public')->delete($company->{$request->type});
            }

            // Upload new image
            $path = $request->file('image')->store('company_images', 'public');

            $company->update([
                $request->type => $path
            ]);

            return response()->json([
                'success' => true,
                'message' => ucfirst($request->type) . ' uploaded successfully',
                'path' => $path
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
     * Delete company image (logo or image1/image2/image3)
     * Auto-deletes file from storage
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
                // Delete file from storage
                if (Storage::disk('public')->exists($company->{$type})) {
                    Storage::disk('public')->delete($company->{$type});
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
    public function searchCompaniesWithFilters(Request $request)
    {
        try {
            $filters = $request->only(['name', 'location', 'industry', 'priority']);

            $query = Company::query();

            if (isset($filters['name'])) {
                $query->where('name', 'like', '%' . $filters['name'] . '%');
            }

            if (isset($filters['location'])) {
                $query->where('address', 'like', '%' . $filters['location'] . '%');
            }

            if (isset($filters['industry'])) {
                $query->where('industry', $filters['industry']);
            }

            if (isset($filters['priority'])) {
                $query->where('search_priority', $filters['priority']);
            }

            $companies = $query->get();

            return response()->json(['success' => true, 'companies' => $companies], 200);
        } catch (\Exception $e) {
            Log::error('Error searching companies', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Unable to search companies'], 500);
        }
    }
}
