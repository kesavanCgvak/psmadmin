<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeSubscriptionService;
use App\Models\Subscription;
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
            
            // Send cancellation confirmation email
            try {
                $email = $user->profile->email ?? $user->email;
                if ($email) {
                    Mail::to($email)->send(new SubscriptionCanceledNotification($user, $subscription, false));
                    Log::info('Cancellation confirmation email sent', [
                        'user_id' => $user->id,
                        'subscription_id' => $subscription->id,
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
            
            $paymentMethodId = $validated['payment_method_id'];

            // Ensure payment method is attached to the customer before setting default
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
            if ($paymentMethod->customer && $paymentMethod->customer !== $user->stripe_customer_id) {
                throw new \Exception('Payment method is attached to a different customer.');
            }

            if (!$paymentMethod->customer) {
                $paymentMethod->attach(['customer' => $user->stripe_customer_id]);
            }

            // Update default payment method on customer
            \Stripe\Customer::update(
                $user->stripe_customer_id,
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

    /**
     * Get default payment method
     */
    public function getPaymentMethod(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user->stripe_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Stripe customer found',
                ], 404);
            }

            // Retrieve customer with expanded default payment method
            $customer = \Stripe\Customer::retrieve(
                $user->stripe_customer_id,
                ['expand' => ['invoice_settings.default_payment_method']]
            );

            $defaultPaymentMethod = $customer->invoice_settings->default_payment_method ?? null;

            if (!$defaultPaymentMethod) {
                return response()->json([
                    'success' => true,
                    'payment_method' => null,
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
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            if (!$user->stripe_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Stripe customer found',
                ], 404);
            }

            // Get query parameters for pagination
            $limit = $request->input('limit', 10);
            $startingAfter = $request->input('starting_after', null);
            $endingBefore = $request->input('ending_before', null);

            // Build parameters for Stripe API
            $params = [
                'customer' => $user->stripe_customer_id,
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
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get billing history', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve billing history',
            ], 500);
        }
    }

    /**
     * Download invoice PDF
     */
    public function downloadInvoice(Request $request, $invoiceId)
    {
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


