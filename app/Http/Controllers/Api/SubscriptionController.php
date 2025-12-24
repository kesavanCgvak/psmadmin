<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeSubscriptionService;
use App\Models\Subscription;
use App\Models\Setting;
use App\Mail\SubscriptionCanceledNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;
use Stripe\Stripe;
use Stripe\Exception\ApiErrorException;

class SubscriptionController extends Controller
{
    protected $subscriptionService;

    public function __construct(StripeSubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Check if payment is enabled, return error if disabled
     */
    protected function checkPaymentEnabled()
    {
        if (!Setting::isPaymentEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment is currently disabled. Subscription features are not available.',
                'payment_enabled' => false,
            ], 403);
        }
        return null;
    }

    /**
     * Get current subscription
     */
    public function getCurrent(Request $request)
    {
        // Check if payment is enabled
        $paymentCheck = $this->checkPaymentEnabled();
        if ($paymentCheck) {
            return $paymentCheck;
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            // Load company and subscription relationships
            $user->load(['company', 'subscription']);
            
            $subscription = null;
            $isCompanySubscription = false;
            
            // Check if user belongs to a provider company
            if ($user->company) {
                $accountType = strtolower($user->company->account_type ?? '');
                
                if ($accountType === 'provider') {
                    // Load company subscription explicitly
                    $user->company->load('subscription');
                    
                    // If relationship didn't load, try direct query
                    if (!$user->company->subscription) {
                        // Try to find subscription by company_id
                        $companySubscription = Subscription::where('company_id', $user->company->id)
                            ->where('account_type', 'provider')
                            ->latest()
                            ->first();
                        
                        // If not found by company_id, try to find provider owner's subscription
                        if (!$companySubscription) {
                            $providerOwner = $user->company->providerOwner();
                            if ($providerOwner) {
                                $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                    ->where('company_id', $user->company->id)
                                    ->where('account_type', 'provider')
                                    ->latest()
                                    ->first();
                                
                                // If still not found, try without company_id (for older subscriptions)
                                if (!$companySubscription) {
                                    $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                        ->where('account_type', 'provider')
                                        ->latest()
                                        ->first();
                                }
                            }
                        }
                        
                        if ($companySubscription) {
                            $user->company->setRelation('subscription', $companySubscription);
                        }
                    }
                    
                    // Use company subscription if found
                    if ($user->company->subscription) {
                        $subscription = $user->company->subscription;
                        $isCompanySubscription = true;
                        
                        Log::info('Provider company user - using company subscription', [
                            'user_id' => $user->id,
                            'company_id' => $user->company->id,
                            'subscription_id' => $subscription->id,
                            'subscription_status' => $subscription->stripe_status,
                        ]);
                    } else {
                        // Debug: Check if any subscriptions exist for this company
                        $allCompanySubscriptions = Subscription::where('company_id', $user->company->id)->get();
                        $providerOwner = $user->company->providerOwner();
                        $ownerSubscriptions = $providerOwner ? Subscription::where('user_id', $providerOwner->id)->get() : collect();
                        
                        Log::warning('Provider company user - no company subscription found', [
                            'user_id' => $user->id,
                            'company_id' => $user->company->id,
                            'company_account_type' => $user->company->account_type,
                            'subscriptions_by_company_id' => $allCompanySubscriptions->count(),
                            'provider_owner_id' => $providerOwner?->id,
                            'subscriptions_by_owner_id' => $ownerSubscriptions->count(),
                            'subscription_ids' => $allCompanySubscriptions->pluck('id')->toArray(),
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
            
            if (!$subscription) {
                // For provider company users, provide helpful message
                if ($user->company && strtolower($user->company->account_type ?? '') === 'provider') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your company subscription has expired or is inactive. Please contact your company administrator.',
                        'is_company_subscription' => true,
                        'company_id' => $user->company_id,
                    ], 404);
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'No subscription found',
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'subscription' => [
                    'id' => $subscription->id,
                    'plan_name' => $subscription->plan_name,
                    'status' => $subscription->stripe_status,
                    'amount' => (float) $subscription->amount,
                    'currency' => $subscription->currency,
                    'trial_ends_at' => $subscription->trial_ends_at?->format('c'),
                    'current_period_end' => $subscription->current_period_end?->format('c'),
                    'is_active' => $subscription->isActive(),
                    'is_trialing' => $subscription->isOnTrial(),
                    'is_payment_failed' => $subscription->isPaymentFailed(),
                    'is_company_subscription' => $isCompanySubscription,
                    'company_id' => $isCompanySubscription ? $user->company_id : null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get current subscription', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve subscription',
            ], 500);
        }
    }

    /**
     * Cancel subscription
     */
    public function cancel(Request $request)
    {
        // Check if payment is enabled
        $paymentCheck = $this->checkPaymentEnabled();
        if ($paymentCheck) {
            return $paymentCheck;
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            // Load company and subscription relationships
            $user->load(['company.subscription', 'subscription']);
            
            // Get effective subscription (company subscription for provider companies, individual for regular users)
            $subscription = null;
            $isCompanySubscription = false;

            // For provider company users, use company subscription
            if ($user->company && strtolower($user->company->account_type ?? '') === 'provider') {
                // Load company subscription
                $user->company->load('subscription');
                
                // If relationship didn't load, try direct query
                if (!$user->company->subscription) {
                    $companySubscription = Subscription::where('company_id', $user->company->id)
                        ->where('account_type', 'provider')
                        ->latest()
                        ->first();
                    
                    if (!$companySubscription) {
                        $providerOwner = $user->company->providerOwner();
                        if ($providerOwner) {
                            $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                ->where('company_id', $user->company->id)
                                ->where('account_type', 'provider')
                                ->latest()
                                ->first();
                            
                            if (!$companySubscription) {
                                $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                    ->where('account_type', 'provider')
                                    ->latest()
                                    ->first();
                            }
                        }
                    }
                    
                    if ($companySubscription) {
                        $user->company->setRelation('subscription', $companySubscription);
                    }
                }
                
                if ($user->company->subscription) {
                    $subscription = $user->company->subscription;
                    $isCompanySubscription = true;
                }
            } else {
                // Regular user - use their own subscription
                $subscription = $user->subscription;
            }
            
            if (!$subscription) {
                $message = 'No subscription found';
                if ($user->company && strtolower($user->company->account_type ?? '') === 'provider') {
                    $message = 'Your company subscription has expired or is inactive. Please contact your company administrator.';
                }
                
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'is_company_subscription' => $isCompanySubscription,
                ], 404);
            }
            
            // Cancel at period end (service continues until then)
            $this->subscriptionService->cancelSubscription($subscription->stripe_subscription_id);
            
            // Refresh subscription from database
            $subscription->refresh();
            
            // Send cancellation confirmation email
            try {
                $email = $user->profile->email ?? $user->email;
                if ($email) {
                    Mail::to($email)->send(new SubscriptionCanceledNotification($user, $subscription, false));
                    Log::info('Cancellation confirmation email sent', [
                        'user_id' => $user->id,
                        'subscription_id' => $subscription->id,
                        'is_company_subscription' => $isCompanySubscription,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send cancellation confirmation email', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
                // Don't fail the cancellation if email fails
            }
            
            $message = 'Subscription will be canceled at end of billing period. Service continues until ' . $subscription->current_period_end?->format('Y-m-d');
            if ($isCompanySubscription) {
                $message = 'Company subscription will be canceled at end of billing period. Service continues until ' . $subscription->current_period_end?->format('Y-m-d') . '. All users in your company will be affected.';
            }
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'cancel_at_period_end' => true,
                'current_period_end' => $subscription->current_period_end?->format('c'),
                'is_company_subscription' => $isCompanySubscription,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel subscription', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription',
            ], 500);
        }
    }

    /**
     * Update payment method
     */
    public function updatePaymentMethod(Request $request)
    {
        // Check if payment is enabled
        $paymentCheck = $this->checkPaymentEnabled();
        if ($paymentCheck) {
            return $paymentCheck;
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            // Load company and subscription relationships
            $user->load(['company.subscription', 'subscription']);
            
            $validated = $request->validate([
                'payment_method_id' => 'required|string|starts_with:pm_',
                'billing_details' => 'nullable|array',
            ]);
            
            // Get effective subscription (company subscription for provider companies, individual for regular users)
            $subscription = null;
            $stripeCustomerId = null;
            $isCompanySubscription = false;

            // For provider company users, use company subscription
            if ($user->company && strtolower($user->company->account_type ?? '') === 'provider') {
                // Load company subscription
                $user->company->load('subscription');
                
                // If relationship didn't load, try direct query
                if (!$user->company->subscription) {
                    $companySubscription = Subscription::where('company_id', $user->company->id)
                        ->where('account_type', 'provider')
                        ->latest()
                        ->first();
                    
                    if (!$companySubscription) {
                        $providerOwner = $user->company->providerOwner();
                        if ($providerOwner) {
                            $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                ->where('company_id', $user->company->id)
                                ->where('account_type', 'provider')
                                ->latest()
                                ->first();
                            
                            if (!$companySubscription) {
                                $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                    ->where('account_type', 'provider')
                                    ->latest()
                                    ->first();
                            }
                        }
                    }
                    
                    if ($companySubscription) {
                        $user->company->setRelation('subscription', $companySubscription);
                    }
                }
                
                if ($user->company->subscription) {
                    $subscription = $user->company->subscription;
                    $stripeCustomerId = $subscription->stripe_customer_id;
                    $isCompanySubscription = true;
                } else {
                    // Fallback to provider owner's customer ID
                    $providerOwner = $user->company->providerOwner();
                    if ($providerOwner && $providerOwner->stripe_customer_id) {
                        $stripeCustomerId = $providerOwner->stripe_customer_id;
                    }
                }
            } else {
                // Regular user - use their own subscription
                $subscription = $user->subscription;
                $stripeCustomerId = $user->stripe_customer_id;
            }
            
            if (!$subscription) {
                $message = 'No subscription found';
                if ($user->company && strtolower($user->company->account_type ?? '') === 'provider') {
                    $message = 'Your company subscription has expired or is inactive. Please contact your company administrator.';
                }
                
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'is_company_subscription' => $isCompanySubscription,
                ], 404);
            }

            if (!$stripeCustomerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Stripe customer found',
                ], 404);
            }
            
            $paymentMethodId = $validated['payment_method_id'];

            // Ensure payment method is attached to the customer before setting default
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            if ($paymentMethod->customer && $paymentMethod->customer !== $stripeCustomerId) {
                throw new \Exception('Payment method is attached to a different customer.');
            }

            if (!$paymentMethod->customer) {
                $paymentMethod->attach(['customer' => $stripeCustomerId]);
            }

            // Update default payment method on customer
            \Stripe\Customer::update(
                $stripeCustomerId,
                [
                    'invoice_settings' => [
                        'default_payment_method' => $paymentMethodId
                    ]
                ]
            );

            // Also set default payment method on the subscription itself
            \Stripe\Subscription::update(
                $subscription->stripe_subscription_id,
                [
                    'default_payment_method' => $paymentMethodId,
                ]
            );
            
            // If subscription is past_due, attempt to pay the invoice
            if ($subscription->stripe_status === 'past_due') {
                $invoices = \Stripe\Invoice::all([
                    'customer' => $stripeCustomerId,
                    'subscription' => $subscription->stripe_subscription_id,
                    'status' => 'open',
                    'limit' => 1,
                ]);
                
                if (!empty($invoices->data)) {
                    $invoice = $invoices->data[0];
                    $invoice->pay([
                        'payment_method' => $validated['payment_method_id']
                    ]);
                    
                    // Sync subscription status
                    $this->subscriptionService->syncSubscriptionFromStripe($subscription->stripe_subscription_id);
                    $subscription->refresh();
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
                'subscription_status' => $subscription->stripe_status,
                'is_company_subscription' => $isCompanySubscription,
            ]);
            
        } catch (\Stripe\Exception\CardException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Card was declined: ' . $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::error('Failed to update payment method', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment method'
            ], 500);
        }
    }

    /**
     * Get default payment method
     */
    public function getPaymentMethod(Request $request)
    {
        // Check if payment is enabled
        $paymentCheck = $this->checkPaymentEnabled();
        if ($paymentCheck) {
            return $paymentCheck;
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            // Load company and subscription relationships
            $user->load(['company.subscription', 'subscription']);

            // Determine which Stripe customer ID to use
            $stripeCustomerId = null;
            $subscription = null;
            $isCompanySubscription = false;

            // For provider company users, use company subscription's customer ID
            if ($user->company && strtolower($user->company->account_type ?? '') === 'provider') {
                // Load company subscription
                $user->company->load('subscription');
                
                // If relationship didn't load, try direct query
                if (!$user->company->subscription) {
                    $companySubscription = Subscription::where('company_id', $user->company->id)
                        ->where('account_type', 'provider')
                        ->latest()
                        ->first();
                    
                    if (!$companySubscription) {
                        $providerOwner = $user->company->providerOwner();
                        if ($providerOwner) {
                            $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                ->where('company_id', $user->company->id)
                                ->where('account_type', 'provider')
                                ->latest()
                                ->first();
                            
                            if (!$companySubscription) {
                                $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                    ->where('account_type', 'provider')
                                    ->latest()
                                    ->first();
                            }
                        }
                    }
                    
                    if ($companySubscription) {
                        $user->company->setRelation('subscription', $companySubscription);
                    }
                }
                
                if ($user->company->subscription) {
                    $subscription = $user->company->subscription;
                    $stripeCustomerId = $subscription->stripe_customer_id;
                    $isCompanySubscription = true;
                } else {
                    // Fallback to provider owner's customer ID
                    $providerOwner = $user->company->providerOwner();
                    if ($providerOwner && $providerOwner->stripe_customer_id) {
                        $stripeCustomerId = $providerOwner->stripe_customer_id;
                    }
                }
            } else {
                // Regular user - use their own customer ID
                $stripeCustomerId = $user->stripe_customer_id;
                $subscription = $user->subscription;
            }

            if (!$stripeCustomerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Stripe customer found',
                    'is_company_subscription' => $isCompanySubscription,
                ], 404);
            }

            // Retrieve customer with expanded default payment method
            $customer = \Stripe\Customer::retrieve(
                $stripeCustomerId,
                ['expand' => ['invoice_settings.default_payment_method']]
            );

            $defaultPaymentMethod = $customer->invoice_settings->default_payment_method ?? null;

            // Fallback: if customer has no default_payment_method, try subscription's default
            if (!$defaultPaymentMethod && $subscription) {
                $stripeSubscription = \Stripe\Subscription::retrieve($subscription->stripe_subscription_id);
                $defaultPaymentMethod = $stripeSubscription->default_payment_method ?? null;
            }

            if (!$defaultPaymentMethod) {
                return response()->json([
                    'success' => true,
                    'payment_method' => null,
                    'is_company_subscription' => $isCompanySubscription,
                ]);
            }

            // If Stripe returned only the ID, fetch full payment method details
            if (is_string($defaultPaymentMethod)) {
                $defaultPaymentMethod = \Stripe\PaymentMethod::retrieve($defaultPaymentMethod);
            }

            $card = $defaultPaymentMethod->card ?? null;

            return response()->json([
                'success' => true,
                'payment_method' => [
                    'id' => $defaultPaymentMethod->id,
                    'brand' => $card->brand ?? null,
                    'last4' => $card->last4 ?? null,
                    'exp_month' => $card->exp_month ?? null,
                    'exp_year' => $card->exp_year ?? null,
                    'funding' => $card->funding ?? null,
                    'country' => $card->country ?? null,
                ],
                'is_company_subscription' => $isCompanySubscription,
            ]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            Log::error('Payment method not found', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment method not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to get payment method', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment method',
            ], 500);
        }
    }

    /**
     * Get billing history (list of invoices)
     */
    public function billingHistory(Request $request)
    {
        // Check if payment is enabled
        $paymentCheck = $this->checkPaymentEnabled();
        if ($paymentCheck) {
            return $paymentCheck;
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            // Load company and subscription relationships
            $user->load(['company.subscription', 'subscription']);

            // Determine which Stripe customer ID to use
            $stripeCustomerId = null;
            $subscription = null;
            $isCompanySubscription = false;

            // For provider company users, use company subscription's customer ID
            if ($user->company && strtolower($user->company->account_type ?? '') === 'provider') {
                // Load company subscription
                $user->company->load('subscription');
                
                // If relationship didn't load, try direct query
                if (!$user->company->subscription) {
                    $companySubscription = Subscription::where('company_id', $user->company->id)
                        ->where('account_type', 'provider')
                        ->latest()
                        ->first();
                    
                    if (!$companySubscription) {
                        $providerOwner = $user->company->providerOwner();
                        if ($providerOwner) {
                            $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                ->where('company_id', $user->company->id)
                                ->where('account_type', 'provider')
                                ->latest()
                                ->first();
                            
                            if (!$companySubscription) {
                                $companySubscription = Subscription::where('user_id', $providerOwner->id)
                                    ->where('account_type', 'provider')
                                    ->latest()
                                    ->first();
                            }
                        }
                    }
                    
                    if ($companySubscription) {
                        $user->company->setRelation('subscription', $companySubscription);
                    }
                }
                
                if ($user->company->subscription) {
                    $subscription = $user->company->subscription;
                    $stripeCustomerId = $subscription->stripe_customer_id;
                    $isCompanySubscription = true;
                } else {
                    // Fallback to provider owner's customer ID
                    $providerOwner = $user->company->providerOwner();
                    if ($providerOwner && $providerOwner->stripe_customer_id) {
                        $stripeCustomerId = $providerOwner->stripe_customer_id;
                    }
                }
            } else {
                // Regular user - use their own customer ID
                $stripeCustomerId = $user->stripe_customer_id;
                $subscription = $user->subscription;
            }

            if (!$stripeCustomerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Stripe customer found',
                    'is_company_subscription' => $isCompanySubscription,
                ], 404);
            }

            // Get query parameters for pagination
            $limit = $request->input('limit', 10);
            $startingAfter = $request->input('starting_after', null);
            $endingBefore = $request->input('ending_before', null);

            // Build parameters for Stripe API
            $params = [
                'customer' => $stripeCustomerId,
                'limit' => min($limit, 100), // Max 100 per Stripe API
            ];

            if ($startingAfter) {
                $params['starting_after'] = $startingAfter;
            }

            if ($endingBefore) {
                $params['ending_before'] = $endingBefore;
            }

            // Fetch invoices from Stripe
            $invoices = \Stripe\Invoice::all($params);

            // Format invoices for response
            $formattedInvoices = array_map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'amount_due' => $invoice->amount_due / 100, // Convert from cents
                    'amount_paid' => $invoice->amount_paid / 100,
                    'currency' => strtoupper($invoice->currency),
                    'created' => date('c', $invoice->created),
                    'period_start' => $invoice->period_start ? date('c', $invoice->period_start) : null,
                    'period_end' => $invoice->period_end ? date('c', $invoice->period_end) : null,
                    'paid_at' => $invoice->status_transitions->paid_at ? date('c', $invoice->status_transitions->paid_at) : null,
                    'hosted_invoice_url' => $invoice->hosted_invoice_url,
                    'invoice_pdf' => $invoice->invoice_pdf,
                    'description' => $invoice->description,
                    'subscription_id' => $invoice->subscription,
                ];
            }, $invoices->data);

            return response()->json([
                'success' => true,
                'invoices' => $formattedInvoices,
                'has_more' => $invoices->has_more,
                'is_company_subscription' => $isCompanySubscription,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get billing history', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve billing history',
            ], 500);
        }
    }

    /**
     * Create a new subscription for an existing user
     * (used when payment mode is ON and the user registered earlier without a subscription)
     */
    public function create(Request $request)
    {
        // Check if payment is enabled
        $paymentCheck = $this->checkPaymentEnabled();
        if ($paymentCheck) {
            return $paymentCheck;
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();

            // If user already has an active subscription, return it instead of creating a duplicate
            if ($user->subscription) {
                $subscription = $user->subscription;

                return response()->json([
                    'success' => true,
                    'message' => 'Subscription already exists',
                    'subscription' => [
                        'id' => $subscription->id,
                        'plan_name' => $subscription->plan_name,
                        'status' => $subscription->stripe_status,
                        'amount' => (float) $subscription->amount,
                        'currency' => $subscription->currency,
                        'trial_ends_at' => $subscription->trial_ends_at?->format('c'),
                        'current_period_end' => $subscription->current_period_end?->format('c'),
                        'is_active' => $subscription->isActive(),
                        'is_trialing' => $subscription->isOnTrial(),
                        'is_payment_failed' => $subscription->isPaymentFailed(),
                    ],
                ], 200);
            }

            // Ensure payment feature is enabled
            if (!Setting::isPaymentEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment is currently disabled. Please contact support.',
                ], 400);
            }

            $validated = $request->validate([
                'payment_method_id' => 'required|string|starts_with:pm_',
                'billing_details' => 'required|array',
                'billing_details.name' => 'required_with:billing_details|string|max:255',
                'billing_details.email' => 'nullable|email',
                'billing_details.phone' => 'nullable|string|max:20',
                'billing_details.address' => 'required_with:billing_details|array',
                'billing_details.address.line1' => 'required_with:billing_details.address|string',
                'billing_details.address.line2' => 'nullable|string',
                'billing_details.address.city' => 'required_with:billing_details.address|string',
                'billing_details.address.state' => 'required_with:billing_details.address|string',
                'billing_details.address.postal_code' => 'required_with:billing_details.address|string',
                'billing_details.address.country' => 'required_with:billing_details.address|string|size:2',
            ]);

            // Determine account type with priority:
            // 1) Company account_type (if valid)
            // 2) account_type from request (if provided & valid)
            // 3) User.account_type (if valid)
            // 4) Fallback: 'user'
            $validTypes = ['provider', 'user'];
            $companyType = $user->company->account_type ?? null;
            $requestedType = $request->input('account_type');
            $requestedType = $requestedType ? strtolower($requestedType) : null;
            $userType = $user->account_type ?? null;

            if ($companyType && in_array(strtolower($companyType), $validTypes, true)) {
                $accountType = strtolower($companyType);
            } elseif ($requestedType && in_array($requestedType, $validTypes, true)) {
                $accountType = $requestedType;
            } elseif ($userType && in_array(strtolower($userType), $validTypes, true)) {
                $accountType = strtolower($userType);
            } else {
                $accountType = 'user';
            }

            // Ensure Stripe customer exists
            if (!$user->stripe_customer_id) {
                $customer = $this->subscriptionService->createCustomer([
                    'email' => $user->preferred_email ?? $user->email,
                    'name' => $user->profile->full_name ?? $user->username,
                    'metadata' => [
                        'user_id' => $user->id,
                        'company_id' => $user->company_id,
                        'account_type' => $accountType,
                    ],
                ]);

                $user->update(['stripe_customer_id' => $customer->id]);
                $customerId = $customer->id;
            } else {
                $customerId = $user->stripe_customer_id;
            }

            // Attach payment method and create subscription with trial
            $this->subscriptionService->attachPaymentMethod(
                $customerId,
                $validated['payment_method_id'],
                $validated['billing_details']
            );

            // Normalize/validate account type against subscription_plans config
            if (!in_array($accountType, ['provider', 'user'], true)) {
                $accountType = 'user';
            }

            $planConfig = config("subscription_plans.{$accountType}.default");
            if (!$planConfig || empty($planConfig['stripe_price_id'])) {
                throw new \RuntimeException("Subscription plan not configured for account type: {$accountType}");
            }

            $trialDays = $accountType === 'provider' ? 60 : 14;

            $subscription = $this->subscriptionService->createSubscriptionWithTrial(
                customerId: $customerId,
                priceId: $planConfig['stripe_price_id'],
                paymentMethodId: $validated['payment_method_id'],
                trialDays: $trialDays,
                accountType: $accountType,
                userId: $user->id,
                companyId: $user->company_id
            );

            Log::info('Subscription created for existing user', [
                'user_id' => $user->id,
                'account_type' => $accountType,
                'subscription_id' => $subscription->id,
                'trial_days' => $trialDays,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription created successfully',
                'subscription' => [
                    'id' => $subscription->id,
                    'plan_name' => $subscription->plan_name,
                    'status' => $subscription->stripe_status,
                    'amount' => (float) $subscription->amount,
                    'currency' => $subscription->currency,
                    'trial_ends_at' => $subscription->trial_ends_at?->format('c'),
                    'current_period_end' => $subscription->current_period_end?->format('c'),
                    'is_active' => $subscription->isActive(),
                    'is_trialing' => $subscription->isOnTrial(),
                    'is_payment_failed' => $subscription->isPaymentFailed(),
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create subscription for existing user', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create subscription',
            ], 500);
        }
    }

    /**
     * Download invoice PDF
     */
    public function downloadInvoice(Request $request, $invoiceId)
    {
        // Check if payment is enabled
        $paymentCheck = $this->checkPaymentEnabled();
        if ($paymentCheck) {
            return $paymentCheck;
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            if (!$user->stripe_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Stripe customer found',
                ], 404);
            }

            // Retrieve invoice from Stripe
            $invoice = \Stripe\Invoice::retrieve($invoiceId);

            // Verify that the invoice belongs to the authenticated user
            if ($invoice->customer !== $user->stripe_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice not found or access denied',
                ], 403);
            }

            // Check if invoice PDF is available
            if (!$invoice->invoice_pdf) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice PDF is not available yet',
                ], 404);
            }

            // Return invoice PDF URL - frontend can use this to download/open the PDF
            return response()->json([
                'success' => true,
                'invoice_pdf_url' => $invoice->invoice_pdf,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'status' => $invoice->status,
                'amount_paid' => $invoice->amount_paid / 100,
                'currency' => strtoupper($invoice->currency),
            ]);

        } catch (\Stripe\Exception\InvalidRequestException $e) {
            Log::error('Invoice not found', [
                'invoice_id' => $invoiceId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to download invoice', [
                'invoice_id' => $invoiceId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve invoice',
            ], 500);
        }
    }
}


