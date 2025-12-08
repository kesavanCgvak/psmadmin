<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Subscription as StripeSubscription;
use Stripe\PaymentMethod;
use Stripe\Exception\ApiErrorException;

class StripeSubscriptionService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create Stripe customer
     */
    public function createCustomer(array $data): Customer
    {
        try {
            $customer = Customer::create([
                'email' => $data['email'] ?? null,
                'name' => $data['name'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]);

            Log::info('Stripe customer created', [
                'customer_id' => $customer->id,
                'email' => $data['email'] ?? null,
            ]);

            return $customer;
        } catch (ApiErrorException $e) {
            Log::error('Failed to create Stripe customer', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Attach payment method to customer
     */
    public function attachPaymentMethod(string $customerId, string $paymentMethodId, array $billingDetails = []): PaymentMethod
    {
        try {
            // Attach payment method to customer
            $paymentMethod = PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->attach(['customer' => $customerId]);

            // Update billing details if provided
            if (!empty($billingDetails)) {
                $paymentMethod->update($paymentMethodId, [
                    'billing_details' => [
                        'name' => $billingDetails['name'] ?? null,
                        'email' => $billingDetails['email'] ?? null,
                        'phone' => $billingDetails['phone'] ?? null,
                        'address' => $billingDetails['address'] ?? null,
                    ],
                ]);
            }

            Log::info('Payment method attached to customer', [
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId,
            ]);

            return $paymentMethod;
        } catch (ApiErrorException $e) {
            Log::error('Failed to attach payment method', [
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create subscription with trial period
     */
    public function createSubscriptionWithTrial(
        string $customerId,
        string $priceId,
        string $paymentMethodId,
        int $trialDays,
        string $accountType,
        int $userId,
        ?int $companyId = null
    ): Subscription {
        try {
            // Get plan config
            $planConfig = config("subscription_plans.{$accountType}.default");

            // Create subscription in Stripe
            $stripeSubscription = StripeSubscription::create([
                'customer' => $customerId,
                'items' => [
                    ['price' => $priceId],
                ],
                'default_payment_method' => $paymentMethodId,
                'trial_period_days' => $trialDays,
                'metadata' => [
                    'user_id' => $userId,
                    'company_id' => $companyId ?? '',
                    'account_type' => $accountType,
                ],
            ]);

            // Create subscription record in database
            $subscription = Subscription::create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'account_type' => $accountType,
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_customer_id' => $customerId,
                'stripe_price_id' => $priceId,
                'stripe_status' => $stripeSubscription->status,
                'plan_name' => $planConfig['name'],
                'plan_type' => 'default',
                'amount' => $planConfig['amount'],
                'currency' => $planConfig['currency'],
                'interval' => $planConfig['interval'],
                'trial_ends_at' => $stripeSubscription->trial_end 
                    ? now()->setTimestamp($stripeSubscription->trial_end) 
                    : null,
                'current_period_start' => $stripeSubscription->current_period_start 
                    ? now()->setTimestamp($stripeSubscription->current_period_start) 
                    : null,
                'current_period_end' => $stripeSubscription->current_period_end 
                    ? now()->setTimestamp($stripeSubscription->current_period_end) 
                    : null,
            ]);

            // Update user subscription status
            User::where('id', $userId)->update([
                'subscription_status' => $stripeSubscription->status,
            ]);

            Log::info('Subscription created with trial', [
                'subscription_id' => $subscription->id,
                'user_id' => $userId,
                'account_type' => $accountType,
                'trial_days' => $trialDays,
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);

            return $subscription;
        } catch (ApiErrorException $e) {
            Log::error('Failed to create subscription', [
                'customer_id' => $customerId,
                'price_id' => $priceId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Cancel subscription at period end
     */
    public function cancelSubscription(string $stripeSubscriptionId): StripeSubscription
    {
        try {
            $subscription = StripeSubscription::retrieve($stripeSubscriptionId);
            
            // Cancel at period end (service continues until then)
            $subscription->cancel_at_period_end = true;
            $subscription->save();

            // Update local subscription
            Subscription::where('stripe_subscription_id', $stripeSubscriptionId)
                ->update([
                    'stripe_status' => $subscription->status,
                ]);

            Log::info('Subscription set to cancel at period end', [
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);

            return $subscription;
        } catch (ApiErrorException $e) {
            Log::error('Failed to cancel subscription', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync subscription from Stripe
     */
    public function syncSubscriptionFromStripe(string $stripeSubscriptionId): ?Subscription
    {
        try {
            $stripeSubscription = StripeSubscription::retrieve($stripeSubscriptionId);
            $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

            if ($subscription) {
                $subscription->update([
                    'stripe_status' => $stripeSubscription->status,
                    'current_period_start' => $stripeSubscription->current_period_start 
                        ? now()->setTimestamp($stripeSubscription->current_period_start) 
                        : null,
                    'current_period_end' => $stripeSubscription->current_period_end 
                        ? now()->setTimestamp($stripeSubscription->current_period_end) 
                        : null,
                    'trial_ends_at' => $stripeSubscription->trial_end 
                        ? now()->setTimestamp($stripeSubscription->trial_end) 
                        : null,
                ]);

                // Update user subscription status
                $subscription->user->update([
                    'subscription_status' => $stripeSubscription->status,
                ]);
            }

            return $subscription;
        } catch (ApiErrorException $e) {
            Log::error('Failed to sync subscription from Stripe', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}


