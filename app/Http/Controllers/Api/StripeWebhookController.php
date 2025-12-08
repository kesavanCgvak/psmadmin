<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\ApiErrorException;

class StripeWebhookController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Handle Stripe webhook events
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        
        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                config('services.stripe.webhook_secret')
            );
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid payload in webhook', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Invalid signature in webhook', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }
        
        Log::info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
        ]);
        
        try {
            switch ($event->type) {
                case 'customer.subscription.created':
                    $this->handleSubscriptionCreated($event->data->object);
                    break;
                    
                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($event->data->object);
                    break;
                    
                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($event->data->object);
                    break;
                    
                case 'customer.subscription.trial_will_end':
                    $this->handleTrialWillEnd($event->data->object);
                    break;
                
                case 'invoice.payment_succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;
                    
                case 'invoice.payment_failed':
                    $this->handlePaymentFailed($event->data->object);
                    break;
                    
                default:
                    Log::info('Unhandled webhook event type', ['type' => $event->type]);
            }
            
            return response()->json(['received' => true]);
            
        } catch (\Exception $e) {
            Log::error('Error handling webhook', [
                'type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
    
    private function handleSubscriptionCreated($stripeSubscription)
    {
        Log::info('Subscription created webhook', [
            'stripe_subscription_id' => $stripeSubscription->id,
            'customer_id' => $stripeSubscription->customer,
            'status' => $stripeSubscription->status,
        ]);
        
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();
        
        if ($subscription) {
            $subscription->update([
                'stripe_status' => $stripeSubscription->status,
                'current_period_start' => $stripeSubscription->current_period_start 
                    ? now()->setTimestamp($stripeSubscription->current_period_start) 
                    : null,
                'current_period_end' => $stripeSubscription->current_period_end 
                    ? now()->setTimestamp($stripeSubscription->current_period_end) 
                    : null,
            ]);
        }
    }
    
    private function handleSubscriptionUpdated($stripeSubscription)
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)
            ->with('user')
            ->first();
        
        if (!$subscription) {
            Log::warning('Subscription not found for update webhook', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);
            return;
        }
        
        $oldStatus = $subscription->stripe_status;
        $newStatus = $stripeSubscription->status;
        
        $subscription->update([
            'stripe_status' => $newStatus,
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
        
        $subscription->user->update([
            'subscription_status' => $newStatus,
        ]);
        
        Log::info('Subscription updated via webhook', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
        
        if ($oldStatus === 'trialing' && $newStatus === 'active') {
            Log::info('Trial ended, subscription now active', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);
        }
    }
    
    private function handlePaymentSucceeded($invoice)
    {
        if (!$invoice->subscription) {
            return;
        }
        
        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)
            ->with('user')
            ->first();
        
        if (!$subscription) {
            Log::warning('Subscription not found for payment succeeded', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $invoice->subscription,
            ]);
            return;
        }
        
        try {
            $stripeSubscription = \Stripe\Subscription::retrieve($invoice->subscription);
            
            $subscription->update([
                'stripe_status' => $stripeSubscription->status,
                'current_period_start' => $stripeSubscription->current_period_start 
                    ? now()->setTimestamp($stripeSubscription->current_period_start) 
                    : null,
                'current_period_end' => $stripeSubscription->current_period_end 
                    ? now()->setTimestamp($stripeSubscription->current_period_end) 
                    : null,
            ]);
            
            $subscription->user->update([
                'subscription_status' => $stripeSubscription->status,
            ]);
            
            Log::info('Payment succeeded', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'invoice_id' => $invoice->id,
                'amount_paid' => $invoice->amount_paid / 100,
                'subscription_status' => $stripeSubscription->status,
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Failed to retrieve subscription for payment succeeded', [
                'subscription_id' => $invoice->subscription,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    private function handlePaymentFailed($invoice)
    {
        if (!$invoice->subscription) {
            return;
        }
        
        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)
            ->with('user')
            ->first();
        
        if (!$subscription) {
            Log::warning('Subscription not found for payment failed', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $invoice->subscription,
            ]);
            return;
        }
        
        try {
            $stripeSubscription = \Stripe\Subscription::retrieve($invoice->subscription);
            
            $subscription->update([
                'stripe_status' => $stripeSubscription->status,
            ]);
            
            $subscription->user->update([
                'subscription_status' => $stripeSubscription->status,
            ]);
            
            Log::warning('Payment failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'invoice_id' => $invoice->id,
                'amount_due' => $invoice->amount_due / 100,
                'attempt_count' => $invoice->attempt_count ?? 1,
                'subscription_status' => $stripeSubscription->status,
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Failed to retrieve subscription for payment failed', [
                'subscription_id' => $invoice->subscription,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    private function handleSubscriptionDeleted($stripeSubscription)
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)
            ->with('user')
            ->first();
        
        if (!$subscription) {
            Log::warning('Subscription not found for deleted webhook', [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);
            return;
        }
        
        $subscription->update([
            'stripe_status' => 'canceled',
            'ends_at' => now(),
        ]);
        
        $subscription->user->update([
            'subscription_status' => 'canceled',
            'subscription_ends_at' => $subscription->current_period_end,
        ]);
        
        Log::info('Subscription canceled', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'current_period_end' => $subscription->current_period_end,
        ]);
    }
    
    private function handleTrialWillEnd($stripeSubscription)
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)
            ->with('user')
            ->first();
        
        if (!$subscription) {
            return;
        }
        
        Log::info('Trial ending soon', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'trial_end' => $stripeSubscription->trial_end 
                ? now()->setTimestamp($stripeSubscription->trial_end) 
                : null,
        ]);
    }
}


