<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Company;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redirect;

class CompanyUserController extends Controller
{
    /**
     * Create a new company user
     */
    public function store(Request $request)
    {
        try {
            $authUser = JWTAuth::parseToken()->authenticate();

            if (!$authUser || $authUser->role !== 'admin') {
                Log::warning('Unauthorized user creation attempt', ['user_id' => optional($authUser)->id]);
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'username' => 'required|string|unique:users,username|min:3|max:20|regex:/^[a-zA-Z0-9_]+$/',
                'email' => 'required|email',
                'name' => 'required|string',
                'mobile' => 'required|string',
                'password' => 'required|string|min:6',
                'role' => 'required|in:admin,user',
                'company_id' => 'required|exists:companies,id',
            ]);

            if ($validator->fails()) {
                Log::error('Validation failed for user creation', ['errors' => $validator->errors()]);
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            // Check if company can add more users (configurable limit)
            $company = Company::find($request->company_id);
            if ($company) {
                if (!$company->canAddMoreUsers()) {
                    $currentCount = $company->getUserCount();
                    $maxLimit = $company->getMaxUserLimit();
                    Log::warning('Maximum users limit reached for company', [
                        'company_id' => $company->id,
                        'current_count' => $currentCount,
                        'max_limit' => $maxLimit
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => "This company has reached the maximum allowed users ({$maxLimit}). Current users: {$currentCount}."
                    ], 422);
                }
            }

            DB::beginTransaction();

            $user = User::create([
                'account_type' => $authUser->account_type,
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'company_id' => $request->company_id,
                'role' => $request->role,
                'email' => $request->email,
                'is_admin' => $request->role === 'admin',
            ]);

            $user->profile()->create([
                'full_name' => $request->name,
                'email' => $request->email,
                'mobile' => $request->mobile,
            ]);

            DB::commit();

            try {
                $token = Str::random(30);
                $data_user = User::where('id', $user->id)->first();
                $data_user->token = $token;
                $data_user->save();
                $data = array('email' => $request->email);

                Mail::send('emails.verificationEmail', ['token' => $token, 'username' => $request->username], function ($message) use ($data) {
                    $message->to($data['email']);
                    $message->subject('Email Verification Mail');
                    $message->from(config('mail.from.address'), config('mail.from.name')); // <-- set from here
                });

                // Send user credentials email to USER - ALL THE TIME (regardless of verification status)
                // Email is sent TO the user's email address ($request->email)
                Mail::send('emails.registrationSuccess', [
                    'name' => $request->name,
                    'email' => $request->email,
                    'username' => $request->username,
                    'password' => $request->password,
                    'account_type' => $authUser->accountType,
                    'login_url' => env('APP_URL'),
                ], function ($message) use ($request) {
                    $message->to($request->email); // TO: User's email address
                    $message->subject('Welcome to ProSub Marketplace - Account Created Successfully');
                    $message->from(config('mail.from.address'), config('mail.from.name')); // FROM: System email
                });

                $company = Company::find($request->company_id);
                $companyName = $company ? $company->name : null;
                // Mail to app admin that a new user has been created
                Mail::send('emails.newRegistration', [
                    'company_name' => $companyName,
                    'account_type' => $authUser->accountType,
                    'username' => $request->username,
                    'mobile' => $request->mobile,
                    'email' => $request->email
                ], function ($message) use ($data) {
                    $message->to(config('mail.to.addresses'));
                    $message->subject('New registration');
                    $message->from(config('mail.from.address'), config('mail.from.name'));
                });

                Log::info('Email verification notification sent successfully', [
                    'user_id' => $user->id,
                    'user_email' => $request->email,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to send email verification notification', [
                    'user_id' => $user->id,
                    'user_email' => $request->email,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => ['user_id' => $user->id]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User creation failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'User creation failed'], 500);
        }
    }

    /**
     * Get all users in the company
     */
    public function getCompanyUsers()
    {
        try {
            $authUser = JWTAuth::parseToken()->authenticate();
            $company = $authUser->company;

            $users = $company->users()
                ->with(['profile:id,user_id,full_name,email,mobile'])
                ->select(['id', 'is_company_default_contact', 'is_admin'])
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->profile->full_name ?? null,
                        'email' => $user->profile->email ?? null,
                        'mobile' => $user->profile->mobile ?? null,
                        'is_company_default_contact' => $user->is_company_default_contact,
                        'is_admin' => $user->is_admin,
                    ];
                });

            return response()->json([
                'success' => true,
                'users' => $users
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch company users', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Unable to fetch company users'], 500);
        }
    }

    /**
     * Update a company user
     */
    public function updateCompanyUser(Request $request, $id)
    {
        try {
            $authUser = JWTAuth::parseToken()->authenticate();

            if ($authUser->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'mobile' => 'required|string|max:20',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $user = User::where('company_id', $authUser->company_id)->findOrFail($id);

            $user->profile()->update([
                'full_name' => $request->name,
                'mobile' => $request->mobile,
            ]);

            return response()->json(['success' => true, 'message' => 'User updated successfully'], 200);

        } catch (\Exception $e) {
            Log::error('Failed to update user', ['user_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Unable to update user'], 500);
        }
    }

    /**
     * Delete a company user
     */
    public function deleteUser($id)
    {
        try {
            $authUser = JWTAuth::parseToken()->authenticate();

            if ($authUser->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            if ($id == $authUser->id) {
                return response()->json(['success' => false, 'message' => 'Cannot delete your own account'], 403);
            }

            $user = User::where('company_id', $authUser->company_id)->where('id', $id)->first();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            // Get company to fetch user limit info after deletion
            $company = $authUser->company;

            $user->delete();

            // Get updated user limit information after deletion
            $currentUserCount = $company->getUserCount();
            $maxUserLimit = $company->getMaxUserLimit();
            $userLimitInfo = [
                'current_user_count' => $currentUserCount,
                'max_user_limit' => $maxUserLimit,
                'can_create_user' => $currentUserCount < $maxUserLimit,
            ];

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully',
                'user_limit' => $userLimitInfo
            ], 200);

        } catch (\Exception $e) {
            Log::error('Delete user failed', ['user_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to delete user'], 500);
        }
    }


    /**
     * Make or remove admin privileges for a company user
     */
    public function makeAdmin(Request $request, $userId)
    {
        try {
            $authUser = JWTAuth::parseToken()->authenticate();
            $company = $authUser->company;

            if (!$company) {
                return response()->json(['success' => false, 'message' => 'Company not found'], 404);
            }

            $user = $company->users()->find($userId);

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found'], 404);
            }

            $validator = Validator::make($request->all(), [
                'is_admin' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            // Update is_admin and role together
            $isAdmin = $request->is_admin;
            $user->is_admin = $isAdmin;
            $user->role = $isAdmin ? 'admin' : 'user';
            $user->save();

            return response()->json([
                'success' => true,
                'message' => $isAdmin ? 'User promoted to admin' : 'User demoted to user',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'is_admin' => $user->is_admin,
                    'role' => $user->role,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error updating admin status', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Unable to update admin status'], 500);
        }
    }

}
