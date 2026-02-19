<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\City;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\Setting;
use App\Services\StripeSubscriptionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use App\Jobs\SyncUserToHubSpot;


class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        // Check if payment is enabled
        $paymentEnabled = Setting::isPaymentEnabled();

        // Build validation rules based on payment status
        $rules = [
            'account_type' => 'required|string|in:provider,user',
            'company_name' => 'required|string|max:255|unique:companies,name',
            'username' => 'required|string|max:255|unique:users,username',
            'name' => 'required|string|max:255',
            'region' => 'required|exists:regions,id',
            'country_id' => 'required|exists:countries,id',
            'state_id' => 'required|exists:states_provinces,id',
            'city' => 'required|exists:cities,id',
            'birthday' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'mobile' => 'required|string|max:20',
            'terms_accepted' => 'accepted',
        ];

        $customMessages = [
            'account_type.in' => 'Account type must be provider, customer or user.',
            'company_name.unique' => 'This company name is already registered.',
            'username.unique' => 'This username is already taken.',
            'terms_accepted.accepted' => 'You must accept the terms to register.',
        ];

        // Add payment validation only if payment is enabled
        if ($paymentEnabled) {
            $rules['payment_method_id'] = [
                'required',
                'string',
                'starts_with:pm_',
            ];
            $rules['billing_details'] = [
                'required',
                'array',
            ];
            $rules['billing_details.name'] = 'required_with:billing_details|string|max:255';
            $rules['billing_details.email'] = 'nullable|email';
            $rules['billing_details.phone'] = 'nullable|string|max:20';
            $rules['billing_details.address'] = 'required_with:billing_details|array';
            $rules['billing_details.address.line1'] = 'required_with:billing_details.address|string';
            $rules['billing_details.address.line2'] = 'nullable|string';
            $rules['billing_details.address.city'] = 'required_with:billing_details.address|string';
            $rules['billing_details.address.state'] = 'required_with:billing_details.address|string';
            $rules['billing_details.address.postal_code'] = 'required_with:billing_details.address|string';
            $rules['billing_details.address.country'] = 'required_with:billing_details.address|string|size:2';

            $customMessages['payment_method_id.required'] = 'Credit card is required for registration.';
            $customMessages['billing_details.required'] = 'Billing details are required for registration.';
        }

        $validator = Validator::make($request->all(), $rules, $customMessages);
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
            DB::beginTransaction();

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
                'date_format_id' => 1, // Default date format ID
                'pricing_scheme_id' => 1, // Default pricing scheme ID
                'rating' => 5, // Default rating
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

            // Build profile payload explicitly so we can log and avoid nulls
            $profileData = [
                'full_name' => $request->name ?? $request->username,
                'birthday' => $request->birthday, // stored as VARCHAR in DB (e.g. MM-DD)
                // user_profiles.email column is NOT nullable in DB, so make sure we never send null here
                'email' => $request->email ?? $user->email ?? '',
                'mobile' => $request->mobile,
            ];

            Log::info('Profile payload before create', ['profile_data' => $profileData]);

            //Create profile
            $user->profile()->create($profileData);

            Log::info('Profile created', ['profile' => $user->profile]);

            // Handle Stripe Subscription only if payment is enabled
            $subscription = null;

            if ($paymentEnabled) {
                $subscriptionService = new StripeSubscriptionService();

                try {
                    // Create Stripe customer
                    $customer = $subscriptionService->createCustomer([
                        'email' => $request->email,
                        'name' => $request->name ?? $request->username,
                        'metadata' => [
                            'user_id' => $user->id,
                            'company_id' => $company->id,
                            'account_type' => $request->account_type,
                        ],
                    ]);

                    // Update user with Stripe customer ID
                    $user->update(['stripe_customer_id' => $customer->id]);

                    // Attach payment method
                    $subscriptionService->attachPaymentMethod(
                        $customer->id,
                        $request->payment_method_id,
                        $request->billing_details
                    );

                    // Get plan config
                    $planConfig = config("subscription_plans.{$request->account_type}.default");

                    // Create subscription with trial
                    $trialDays = $request->account_type === 'provider' ? 60 : 14;

                    $subscription = $subscriptionService->createSubscriptionWithTrial(
                        customerId: $customer->id,
                        priceId: $planConfig['stripe_price_id'],
                        paymentMethodId: $request->payment_method_id,
                        trialDays: $trialDays,
                        accountType: $request->account_type,
                        userId: $user->id,
                        companyId: $company->id
                    );

                    Log::info('Subscription created during registration', [
                        'user_id' => $user->id,
                        'account_type' => $request->account_type,
                        'subscription_id' => $subscription->id,
                        'trial_days' => $trialDays,
                    ]);

                    // Send subscription details email to the user
                    if ($request->email) {
                        $subscriptionEmailData = [
                            'username' => $request->username,
                            'plan_name' => $subscription->plan_name ?? ucfirst($request->account_type) . ' Plan',
                            'status' => $subscription->stripe_status,
                            'trial_end_date' => $subscription->trial_ends_at
                                ? $subscription->trial_ends_at
                                    ->timezone(config('app.timezone'))
                                    ->format(config('app.display_date_format', 'M d, Y'))
                                : null,
                            'amount' => $subscription->amount,
                            'currency' => strtoupper($subscription->currency ?? 'USD'),
                            'interval' => $subscription->interval,
                            'app_url' => env('APP_FRONTEND_URL'),
                        ];

                        \App\Helpers\EmailHelper::send('subscriptionCreated', $subscriptionEmailData, function ($message) use ($request) {
                            $message->to($request->email);
                            $message->from(config('mail.from.address'), config('mail.from.name'));
                        });
                    }

                } catch (\Exception $e) {
                    Log::error('Failed to create subscription during registration', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw $e; // Re-throw to rollback transaction
                }
            } else {
                Log::info('Registration completed without payment (payment disabled)', [
                    'user_id' => $user->id,
                    'account_type' => $request->account_type,
                ]);
            }

            DB::commit();
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

                \App\Helpers\EmailHelper::send('verificationEmail', ['token' => $token, 'username' => $request->username], function ($message) use ($data) {
                    $message->to($data['email']);
                    $message->from(config('mail.from.address'), config('mail.from.name'));
                });

                Log::info('state', ['state_name' => $company_details->getState->name,]);
                Log::info('Email verification notification sent successfully', [
                    'user_id' => $user->id,
                    'user_email' => $request->email,
                ]);

                \App\Helpers\EmailHelper::send('newRegistration', [
                    'company_name' => $request->company_name,
                    'account_type' => $request->account_type,
                    'username' => $request->username,
                    'region_name' => $company_details->getregion->name,
                    'country_name' => $company_details->getcountry->name,
                    'city_name' => $company_details->getcity->name,
                    'state_name' => $company_details->getState->name,
                    'mobile' => $request->mobile,
                    'email' => $request->email
                ], function ($message) use ($data) {
                    $message->to(config('mail.to.addresses'));
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
                'subscription' => $subscription ? [
                    'status' => $subscription->stripe_status,
                    'trial_ends_at' => $subscription->trial_ends_at?->format('c'),
                    'plan_name' => $subscription->plan_name,
                ] : null,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            // Log the underlying registration error so we can debug 500 responses
            Log::error('User registration error', [
                'request' => $request->all(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed',
                'error' => 'Internal server error, please try again later.',
            ], 500);
        }
    }

    /**
     * Summary of login
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        try {
            // 1️⃣ Validate credentials
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

            // 2️⃣ Attempt authentication (NO TOKEN YET)
            if (!auth()->attempt($request->only('username', 'password'))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid username or password',
                ], 401);
            }

            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unable to retrieve user information',
                ], 500);
            }

            // 3️⃣ Account safety checks
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

            // 4️⃣ Payment & subscription enforcement (BEFORE TOKEN)
            $paymentEnabled = Setting::isPaymentEnabled();
            $subscriptionStatus = [
                'has_subscription' => false,
                'is_active' => false,
                'is_trialing' => false,
                'status' => 'none',
            ];

            if ($paymentEnabled && $user->company) {

                // Load only what is needed
                $user->load([
                    'company.subscription'
                ]);

                $subscriptionMode = $user->company->subscription_mode;

                if ($subscriptionMode === 'paid') {

                    $subscription = $user->company->subscription;

                    if ($subscription) {
                        $subscriptionStatus = [
                            'has_subscription' => true,
                            'is_active' => $subscription->isActive(),
                            'is_trialing' => $subscription->isOnTrial(),
                            'is_payment_failed' => $subscription->isPaymentFailed(),
                            'status' => $subscription->stripe_status,
                            'trial_ends_at' => $subscription->trial_ends_at?->format('c'),
                            'current_period_end' => $subscription->current_period_end?->format('c'),
                            'plan_name' => $subscription->plan_name,
                            'amount' => (float) $subscription->amount,
                            'currency' => $subscription->currency,
                            'payment_required' => $subscription->isPaymentFailed(),
                            'is_company_subscription' => true,
                            'company_id' => $user->company_id,
                        ];
                    }

                    // 🚫 BLOCK NON-ADMIN USERS
                    if (!$subscription || !$subscription->isActive()) {

                        if (!$user->is_admin) {
                            auth()->logout();
                            return response()->json([
                                'status' => 'error',
                                'message' => 'Your company subscription has expired. Please contact your administrator.',
                                'subscription' => $subscriptionStatus,
                            ], 403);
                        }

                        // ✅ Admin allowed to login
                        $subscriptionStatus['admin_override'] = true;
                        $subscriptionStatus['message'] =
                            'Subscription expired. Please update your payment details to restore access.';
                    }
                }
            }

            // 5️⃣ Generate JWT ONLY AFTER ALL CHECKS
            $token = JWTAuth::fromUser($user);

            // 6️⃣ Load remaining relations (post-auth, optimized)
            $user->load([
                'profile',
                'company.currency',
                'company.rentalSoftware',
            ]);

            // 7️⃣ Company user limits
            $userLimitInfo = null;
            if ($user->company) {
                $current = $user->company->getUserCount();
                $max = $user->company->getMaxUserLimit();

                $userLimitInfo = [
                    'current_user_count' => $current,
                    'max_user_limit' => $max,
                    'can_create_user' => $current < $max,
                ];
            }

            // 8️⃣ Final response (UNCHANGED CONTRACT)
            return response()->json([
                'token' => $token,
                'message' => 'Login successful',
                'user_id' => $user->id,
                'user' => new UserResource($user),
                'subscription' => $subscriptionStatus,
                'payment' => [
                    'enabled' => $paymentEnabled,
                    'message' => $paymentEnabled
                        ? 'Payment is required for new registrations'
                        : 'Payment is not required for new registrations',
                ],
                'user_limit' => $userLimitInfo,
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ]);

        } catch (\Throwable $e) {
            \Log::error('Login error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        }
    }

    /**
     * Login user and return token
     */
    public function loginOld(Request $request)
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

            // Eager-load profile, company, and subscription
            $user->load([
                'profile',
                'company',
                'company.currency',
                'company.rentalSoftware',
                'subscription',
            ]);

            // Determine subscription source and build status
            $subscription = null;
            $isCompanySubscription = false;

            // For provider company users, check company subscription first
            if ($user->company) {
                // Check account_type (could be 'provider' or 'Provider')
                $accountType = strtolower($user->company->account_type ?? '');

                if ($accountType === 'provider') {
                    // Load company subscription explicitly - try multiple approaches
                    $user->company->load('subscription');

                    // If relationship didn't load, try direct query
                    if (!$user->company->subscription) {
                        $companySubscription = Subscription::where('company_id', $user->company->id)
                            ->where(function ($query) {
                                $query->where('account_type', 'provider')
                                    ->orWhere('account_type', 'Provider');
                            })
                            ->latest()
                            ->first();

                        if ($companySubscription) {
                            $user->company->setRelation('subscription', $companySubscription);
                        }
                    }

                    // Use company subscription if found
                    if ($user->company->subscription) {
                        $subscription = $user->company->subscription;
                        $isCompanySubscription = true;

                        Log::info('Provider company user login - using company subscription', [
                            'user_id' => $user->id,
                            'company_id' => $user->company->id,
                            'subscription_id' => $subscription->id,
                            'subscription_status' => $subscription->stripe_status,
                        ]);
                    } else {
                        Log::warning('Provider company user login - no company subscription found', [
                            'user_id' => $user->id,
                            'company_id' => $user->company->id,
                            'company_account_type' => $user->company->account_type,
                        ]);
                    }
                } elseif ($user->subscription) {
                    // Regular user with individual subscription
                    $subscription = $user->subscription;
                }
            } elseif ($user->subscription) {
                // Regular user with individual subscription (no company)
                $subscription = $user->subscription;
            }

            if ($subscription) {
                $subscriptionStatus = [
                    'has_subscription' => true,
                    'is_active' => $subscription->isActive(),
                    'is_trialing' => $subscription->isOnTrial(),
                    'is_payment_failed' => $subscription->isPaymentFailed(),
                    'status' => $subscription->stripe_status,
                    'trial_ends_at' => $subscription->trial_ends_at?->format('c'),
                    'current_period_end' => $subscription->current_period_end?->format('c'),
                    'plan_name' => $subscription->plan_name,
                    'amount' => (float) $subscription->amount,
                    'currency' => $subscription->currency,
                    'payment_required' => $subscription->isPaymentFailed(),
                    'is_company_subscription' => $isCompanySubscription,
                    'company_id' => $isCompanySubscription ? $user->company_id : null,
                ];
            } else {
                // For provider company users without subscription, provide helpful message
                $userAccountType = $user->company ? strtolower($user->company->account_type ?? '') : '';

                if ($user->company && $userAccountType === 'provider') {
                    $subscriptionStatus = [
                        'has_subscription' => false,
                        'is_active' => false,
                        'is_trialing' => false,
                        'status' => 'none',
                        'message' => 'Your company subscription has expired or is inactive. Please contact your company administrator.',
                        'is_company_subscription' => true,
                        'company_id' => $user->company_id,
                    ];
                } else {
                    $subscriptionStatus = [
                        'has_subscription' => false,
                        'is_active' => false,
                        'is_trialing' => false,
                        'status' => 'none',
                        'message' => 'No subscription found. Please contact support.',
                    ];
                }
            }

            // Block login if subscription is expired/canceled for provider company users
            if ($user->company && $user->company->account_type === 'provider') {
                if (!$subscription || !$subscription->isActive()) {
                    auth()->logout();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your company subscription has expired or is inactive. Please renew to continue.',
                        'subscription' => $subscriptionStatus,
                    ], 403);
                }
            }

            // Get payment system status (whether payment is enabled/disabled system-wide)
            $paymentEnabled = Setting::isPaymentEnabled();

            // Get user limit information for company users
            $userLimitInfo = null;
            if ($user->company) {
                $currentUserCount = $user->company->getUserCount();
                $maxUserLimit = $user->company->getMaxUserLimit();
                $userLimitInfo = [
                    'current_user_count' => $currentUserCount,
                    'max_user_limit' => $maxUserLimit,
                    'can_create_user' => $currentUserCount < $maxUserLimit,
                ];
            }

            return response()->json([
                'token' => $token,
                'message' => 'Login successful',
                'user_id' => $user->id,
                'user' => new UserResource($user),
                'subscription' => $subscriptionStatus,
                'payment' => [
                    'enabled' => $paymentEnabled,
                    'message' => $paymentEnabled
                        ? 'Payment is required for new registrations'
                        : 'Payment is not required for new registrations',
                ],
                'user_limit' => $userLimitInfo,
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

            // Dispatch HubSpot sync after successful email verification (non-blocking).
            SyncUserToHubSpot::dispatch($user->id);

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

        // Dispatch HubSpot sync after successful email verification (non-blocking).
        Log::info('Dispatching HubSpot sync job for user ID: ' . $user->id);
        SyncUserToHubSpot::dispatch($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Your email has been successfully verified. You can now login.'
        ], 200);
    }

}
