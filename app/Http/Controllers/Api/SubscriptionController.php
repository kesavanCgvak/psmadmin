<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeSubscriptionService;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
     * Get current subscription
     */
    public function getCurrent(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            $subscription = $user->subscription;
            
            if (!$subscription) {
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
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get current subscription', [
                'error' => $e->getMessage(),
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
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            $subscription = $user->subscription;
            
            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subscription found',
                ], 404);
            }
            
            // Cancel at period end (service continues until then)
            $this->subscriptionService->cancelSubscription($subscription->stripe_subscription_id);
            
            // Refresh subscription from database
            $subscription->refresh();
            
            return response()->json([
                'success' => true,
                'message' => 'Subscription will be canceled at end of billing period. Service continues until ' . $subscription->current_period_end?->format('Y-m-d'),
                'cancel_at_period_end' => true,
                'current_period_end' => $subscription->current_period_end?->format('c'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel subscription', [
                'error' => $e->getMessage(),
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
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            $validated = $request->validate([
                'payment_method_id' => 'required|string|starts_with:pm_',
                'billing_details' => 'nullable|array',
            ]);
            
            $subscription = $user->subscription;
            
            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subscription found',
                ], 404);
            }
            
            // Update default payment method on customer
            \Stripe\Customer::update(
                $user->stripe_customer_id,
                [
                    'invoice_settings' => [
                        'default_payment_method' => $validated['payment_method_id']
                    ]
                ]
            );
            
            // If subscription is past_due, attempt to pay the invoice
            if ($subscription->stripe_status === 'past_due') {
                $invoices = \Stripe\Invoice::all([
                    'customer' => $user->stripe_customer_id,
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
            ]);
            
        } catch (\Stripe\Exception\CardException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Card was declined: ' . $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::error('Failed to update payment method', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment method'
            ], 500);
        }
    }
}


