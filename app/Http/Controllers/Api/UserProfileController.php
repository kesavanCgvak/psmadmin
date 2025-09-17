<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserProfileController extends Controller
{
    public function uploadPicture(Request $request)
    {
        try {
            $request->validate([
                'profile_picture' => 'required|image|mimes:jpeg,png,jpg|max:2048'
            ]);

            $user = $request->user();

            // Create directory if it doesn't exist
            $uploadPath = public_path('images/profile_pictures');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // Generate unique filename
            $filename = uniqid() . '.' . $request->file('profile_picture')->getClientOriginalExtension();
            $path = 'images/profile_pictures/' . $filename;

            // Move uploaded file to new location
            $request->file('profile_picture')->move($uploadPath, $filename);

            $user->profile->update([
                'profile_picture' => $path
            ]);

            Log::info('Profile picture updated.', ['user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Profile picture updated successfully.',
                'path' => $path
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update profile picture.', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update profile picture.'
            ], 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required',
                'new_password' => 'required|min:6|confirmed'
            ]);

            $user = $request->user();

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect.'
                ], 403);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            Log::info('Password changed successfully.', ['user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to change password.', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to change password.'
            ], 500);
        }
    }

    /**
     * Get the authenticated user's profile
     */
    public function getProfile(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
            }

            $profile = $user->profile;

            if (!$profile) {
                return response()->json(['success' => false, 'message' => 'User profile not found'], 404);
            }

            $profileDetails = [
                'name' => $profile->full_name,
                'mobile' => $profile->mobile,
                'email' => $user->email,
                'avatar_path' => $profile->profile_picture,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'profile' => $profileDetails
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Failed to retrieve user profile', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch user profile details.'
            ], 500);
        }
    }

    /**
     * Update user's full name
     */
    public function updateName(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = JWTAuth::parseToken()->authenticate();
            $profile = $user->profile;

            if (!$profile) {
                return response()->json(['success' => false, 'message' => 'User profile not found'], 404);
            }

            $profile->update(['full_name' => $request->name]);

            return response()->json([
                'success' => true,
                'message' => 'Name updated successfully',
                'data' => ['name' => $request->name]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Failed to update user name', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update name'
            ], 500);
        }
    }

    /**
     * Update user's email
     */
    public function updateEmail(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = JWTAuth::parseToken()->authenticate();
            $profile = $user->profile;

            if (!$profile) {
                return response()->json(['success' => false, 'message' => 'User profile not found'], 404);
            }

            // Depending on your DB design:
            // If email belongs to users table, update $user->email instead.
            $user->update(['email' => $request->email]);

            return response()->json([
                'success' => true,
                'message' => 'Email updated successfully. Please verify your new email address.',
                'data' => ['email' => $request->email]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Failed to update user email', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update email'
            ], 500);
        }
    }

    /**
     * Update user's mobile number
     */
    public function updateMobile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'mobile' => 'required|string|max:20'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = JWTAuth::parseToken()->authenticate();
            $profile = $user->profile;

            if (!$profile) {
                return response()->json(['success' => false, 'message' => 'User profile not found'], 404);
            }

            $profile->update(['mobile' => $request->mobile]);

            return response()->json([
                'success' => true,
                'message' => 'Mobile number updated successfully',
                'data' => ['mobile' => $request->mobile]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Failed to update user mobile number', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update mobile number'
            ], 500);
        }
    }
}
