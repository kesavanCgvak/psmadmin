<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Models\User;
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

            DB::beginTransaction();

            $user = User::create([
                'account_type' => $authUser->account_type,
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'company_id' => $request->company_id,
                'role' => $request->role,
                'is_admin' => $request->input('is_admin', false),
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

            $user->delete();

            return response()->json(['success' => true, 'message' => 'User deleted successfully'], 200);

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
