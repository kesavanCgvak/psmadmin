<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\City;
use App\Models\Company;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;


class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_type' => 'required|string|in:provider,user',
            'company_name' => 'required|string|max:255|unique:companies,name',
            'username' => 'required|string|max:255|unique:users,username',
            'name' => 'nullable|string|max:255',
            'region' => 'required|exists:regions,id',
            'country_id' => 'required|exists:countries,id',
            'state_id' => 'required|exists:states_provinces,id',
            'city' => 'required|exists:cities,id',
            'birthday' => 'nullable|date|before:today',
            'email' => 'nullable|email|max:255',
            'password' => 'required|string|min:8|confirmed', // password_confirmation required
            'mobile' => 'nullable|string|max:20',
            'terms_accepted' => 'accepted',
        ], [
            // custom messages
            'account_type.in' => 'Account type must be provider, customer or user.',
            'company_name.unique' => 'This company name is already registered.',
            'username.unique' => 'This username is already taken.',
            // 'email.unique' => 'This email is already in use.',
            'terms_accepted.accepted' => 'You must accept the terms to register.',
        ]);
        Log::info('User account verified successfully.', [
            'name' => $request->company_name,
            'region_id' => $request->region,
            'country_id' => $request->country_id,
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            \DB::beginTransaction();

            // Fetch latitude and longitude from the cities table
            $city = City::find($request->city);
            if ($city) {
                $latitude = $city->latitude;
                $longitude = $city->longitude;
            } else {
                // If city is not found, throw an error
                throw new \Exception("City not found");
            }

            //Create company
            $company = Company::create([
                'name' => $request->company_name,
                'account_type' => $request->account_type,
                'region_id' => $request->region,
                'country_id' => $request->country_id,
                'city_id' => $request->city,
                'state_id' => $request->state_id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'currency_id' => 1, // Default currency ID
                'date_format' => 'MM/DD/YYYY',
                'pricing_scheme' => 'Day Price',
            ]);

            //Create user
            $user = User::create([
                'account_type' => $request->account_type,
                'username' => $request->username,
                // 'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'company_id' => $company->id,
                'is_admin' => 1,
                'is_company_default_contact' => 1,
                'role' => $company->users()->count() === 0 ? 'admin' : 'user',
            ]);

            //Set default contact in company
            if ($company->users()->count() === 1) {
                $company->default_contact_id = $user->id;
                $company->save();
            }

            //Create profile
            $user->profile()->create([
                'full_name' => $request->name ?? $request->username,
                'birthday' => $request->birthday,
                'email' => $request->email,
                'mobile' => $request->mobile,
            ]);

            \DB::commit();
            try {
                //$user->sendEmailVerificationNotification();
                $company_details = Company::where('id', $company->id)->first();
                log::info('User account verified successfully.', [
                    'company' => $company_details,
                    'region_id' => $request->region,
                    'country_id' => $request->country_id,
                ]);
                $token = Str::random(30);
                $data_user = User::where('id', $user->id)->first();
                $data_user->token = $token;
                $data_user->save();
                $data = array('email' => $request->email);

                // Mail::send('emails.verificationEmail', ['token' => $token, 'username' => $request->username], function ($message) use ($data) {
                //     $message->to($data['email']);
                //     $message->subject('Email Verification Mail');
                // });
                Mail::send('emails.verificationEmail', ['token' => $token, 'username' => $request->username], function ($message) use ($data) {
                    $message->to($data['email']);
                    $message->subject('Email Verification Mail');
                    $message->from(config('mail.from.address'), config('mail.from.name')); // <-- set from here
                });

                Log::info('state', ['state_name' => $company_details->getState->name,]);
                Log::info('Email verification notification sent successfully', [
                    'user_id' => $user->id,
                    'user_email' => $request->email,
                ]);

                Mail::send('emails.newRegistration', [
                    'company_name' => $request->company_name,
                    'username' => $request->username,
                    'region_name' => $company_details->getregion->name,
                    'country_name' => $company_details->getcountry->name,
                    'city_name' => $company_details->getcity->name,
                    'state_name' => $company_details->getState->name,
                    'mobile' => $request->mobile,
                    'email' => $request->email
                ], function ($message) use ($data) {
                    $message->to($data['email']);
                    $message->subject('New registration');
                    $message->from(config('mail.from.address'), config('mail.from.name'));
                });

                Log::info('New Registration mail sent successfully', [
                    'user_id' => $user->id,
                    'user_email' => $request->email,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send email verification notification', [
                    'user_id' => $user->id,
                    'user_email' => $request->email,
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the registration if email fails
            }

            Log::info('User registration committed');

            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully',
            ], 201);

        } catch (\Exception $e) {
            \DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed',
                'error' => 'Internal server error, please try again later.',
            ], 500);
        }
    }

    /**
     * Login user and return token
     */
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $credentials = $request->only('username', 'password');
            $token = JWTAuth::attempt($credentials);

            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid username or password',
                ], 401);
            }

            $user = JWTAuth::user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unable to retrieve user information',
                ], 500);
            }

            if ($user->is_blocked) {
                auth()->logout();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your account has been blocked. Contact support.',
                ], 403);
            }

            if (!$user->email_verified) {
                auth()->logout();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please verify your email before logging in.',
                ], 403);
            }

            // Eager-load profile and company
            $user->load([
                'profile',
                'company',
                'company.currency',
                'company.rentalSoftware',
            ]);

            /*--return response()->json([
                'token' => $token,
                'message' => 'Login successful',
                'user_id' => $user->id,
                'user' => $user,
            ]);--*/

            return response()->json([
                'token' => $token,
                'message' => 'Login successful',
                'user_id' => $user->id,
                'user' => new UserResource($user),
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ]);

        } catch (\Exception $e) {
            \Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        }
    }


    /**
     * Get logged-in user profile
     */
    public function profile()
    {
        $user = auth()->user();
        if ($user) {
            $user->load(['profile', 'company']);
        }

        return response()->json([
            'status' => 'success',
            'user' => new \App\Http\Resources\UserResource($user)
        ]);
    }

    /**
     * Logout user
     */
    public function logout()
    {
        auth()->logout();
        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out',
        ]);
    }

    public function refresh()
    {
        try {
            $newToken = JWTAuth::parseToken()->refresh();

            return response()->json([
                'access_token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed. Please log in again.'
            ], 401);
        }
    }

    public function verifyAccount_from_bakcend($token)
    {
        try {
            $user = User::where('token', $token)->first();

            if (!$user) {
                Log::warning('Account verification failed: Invalid token.', [
                    'token' => $token
                ]);

                return Redirect::to(env('APP_FRONTEND_URL') . '/verification?status=failed&message=Invalid%20verification%20link');
            }

            if ($user->email_verified) {
                return Redirect::to(env('APP_FRONTEND_URL') . '/verification?status=success&message=Email%20already%20verified');
            }

            $user->email_verified = 1;
            $user->token = null; // clear token after verification
            $user->save();

            Log::info('User account verified successfully.', [
                'user_id' => $user->id,
                'email' => $user->email ?? null
            ]);

            return Redirect::to(env('APP_FRONTEND_URL') . '/verification?status=success&message=Email%20verified%20successfully');
        } catch (\Throwable $e) {
            Log::error('Account verification error.', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);

            return Redirect::to(env('APP_FRONTEND_URL') . '/verification?status=failed&message=Something%20went%20wrong');
        }
    }

    public function verifyAccount(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $user = User::where('token', $request->token)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification link.'
            ], 400);
        }

        if ($user->email_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified.'
            ], 400);
        }

        $user->email_verified = 1;
        $user->token = null; // clear token
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Your email has been successfully verified. You can now login.'
        ], 200);
    }

}

