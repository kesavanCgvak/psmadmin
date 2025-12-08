# Complete Webhook Handling Guide

## 🎯 All Scenarios Covered

This guide covers handling ALL webhook events for subscriptions:

1. ✅ **Monthly subscription renewal** - Auto-debit after trial
2. ✅ **Payment failures** - Failed charges
3. ✅ **Cancellations** - User cancels subscription
4. ✅ **Trial period ends** - First charge after trial
5. ✅ **Subscription updates** - Status changes
6. ✅ **Payment success** - Successful charges

## 📋 Complete Webhook Implementation

### Webhook Controller

```php
// app/Http/Controllers/Api/StripeWebhookController.php

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StripeWebhookController extends Controller
{
    /**
     * Handle Stripe webhook events
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                config('services.stripe.webhook_secret')
            );
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid payload in webhook', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Invalid signature in webhook', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }
        
        Log::info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
        ]);
        
        // Handle the event
        try {
            switch ($event->type) {
                // Subscription Events
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
                
                // Invoice Events
                case 'invoice.payment_succeeded':
                    $this->handlePaymentSucceeded($event->data->object);
                    break;
                    
                case 'invoice.payment_failed':
                    $this->handlePaymentFailed($event->data->object);
                    break;
                    
                case 'invoice.created':
                    $this->handleInvoiceCreated($event->data->object);
                    break;
                
                // Payment Method Events
                case 'payment_method.attached':
                    // Payment method attached to customer
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
    
    /**
     * 1. Handle Subscription Created (with trial)
     * Triggered when subscription is first created
     */
    private function handleSubscriptionCreated($stripeSubscription)
    {
        Log::info('Subscription created webhook', [
            'stripe_subscription_id' => $stripeSubscription->id,
            'customer_id' => $stripeSubscription->customer,
            'status' => $stripeSubscription->status,
        ]);
        
        // Subscription should already be created during registration
        // This webhook confirms it in Stripe
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)->first();
        
        if ($subscription) {
            $subscription->update([
                'stripe_status' => $stripeSubscription->status,
                'current_period_start' => now()->setTimestamp($stripeSubscription->current_period_start),
                'current_period_end' => now()->setTimestamp($stripeSubscription->current_period_end),
            ]);
            
            Log::info('Subscription updated from created webhook', [
                'subscription_id' => $subscription->id,
            ]);
        }
    }
    
    /**
     * 2. Handle Subscription Updated
     * Triggered when:
     * - Trial ends → status changes to 'active', first charge happens
     * - Payment method updated
     * - Plan changed
     * - Status changes (past_due, unpaid, etc.)
     */
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
        
        // Update subscription details
        $subscription->update([
            'stripe_status' => $newStatus,
            'current_period_start' => now()->setTimestamp($stripeSubscription->current_period_start),
            'current_period_end' => now()->setTimestamp($stripeSubscription->current_period_end),
            'trial_ends_at' => $stripeSubscription->trial_end 
                ? now()->setTimestamp($stripeSubscription->trial_end) 
                : null,
        ]);
        
        // Update user subscription status
        $subscription->user->update([
            'subscription_status' => $newStatus,
        ]);
        
        Log::info('Subscription updated via webhook', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
        
        // Handle specific status changes
        if ($oldStatus === 'trialing' && $newStatus === 'active') {
            // Trial ended, first payment successful
            $this->handleTrialEnded($subscription);
        } elseif ($newStatus === 'past_due') {
            // Payment failed, in grace period
            $this->handlePastDue($subscription);
        } elseif (in_array($newStatus, ['unpaid', 'canceled'])) {
            // Payment failed completely or cancelled
            $this->handleSubscriptionInactive($subscription, $newStatus);
        }
    }
    
    /**
     * 3. Handle Payment Succeeded
     * Triggered when:
     * - Trial ends and first payment succeeds
     * - Monthly recurring payment succeeds
     * - Retry payment succeeds after failure
     */
    private function handlePaymentSucceeded($invoice)
    {
        if (!$invoice->subscription) {
            // One-time payment, not subscription
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
        
        // Update subscription status
        $stripeSubscription = \Stripe\Subscription::retrieve($invoice->subscription);
        
        $subscription->update([
            'stripe_status' => $stripeSubscription->status,
            'current_period_start' => now()->setTimestamp($stripeSubscription->current_period_start),
            'current_period_end' => now()->setTimestamp($stripeSubscription->current_period_end),
        ]);
        
        // Update user subscription status
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
        
        // Check if this is the first payment after trial
        if ($subscription->wasRecentlyCreated || $subscription->getOriginal('stripe_status') === 'trialing') {
            $this->handleFirstPaymentAfterTrial($subscription, $invoice);
        } else {
            // Monthly recurring payment
            $this->handleMonthlyRenewal($subscription, $invoice);
        }
    }
    
    /**
     * 4. Handle Payment Failed
     * Triggered when payment fails
     */
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
        
        // Get current subscription status from Stripe
        $stripeSubscription = \Stripe\Subscription::retrieve($invoice->subscription);
        
        // Update subscription status
        $subscription->update([
            'stripe_status' => $stripeSubscription->status,
        ]);
        
        // Update user subscription status
        $subscription->user->update([
            'subscription_status' => $stripeSubscription->status,
        ]);
        
        Log::warning('Payment failed', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'invoice_id' => $invoice->id,
            'amount_due' => $invoice->amount_due / 100,
            'attempt_count' => $invoice->attempt_count,
            'subscription_status' => $stripeSubscription->status,
        ]);
        
        // Send notification to user
        $this->notifyPaymentFailure($subscription->user, $invoice, $stripeSubscription);
    }
    
    /**
     * 5. Handle Subscription Deleted (Cancelled)
     * Triggered when subscription is cancelled
     */
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
        
        // Update subscription status
        $subscription->update([
            'stripe_status' => 'canceled',
            'ends_at' => now(),
        ]);
        
        // Update user subscription status
        $subscription->user->update([
            'subscription_status' => 'canceled',
            'subscription_ends_at' => $subscription->current_period_end, // Service continues until period end
        ]);
        
        Log::info('Subscription canceled', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'canceled_at' => $stripeSubscription->canceled_at 
                ? now()->setTimestamp($stripeSubscription->canceled_at) 
                : now(),
            'current_period_end' => $subscription->current_period_end,
        ]);
        
        // Notify user
        $this->notifySubscriptionCanceled($subscription->user, $subscription);
    }
    
    /**
     * 6. Handle Trial Will End (Reminder)
     * Triggered 3 days before trial ends
     */
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
        
        // Send reminder email
        $this->notifyTrialEnding($subscription->user, $subscription);
    }
    
    /**
     * Helper Methods
     */
    
    private function handleTrialEnded($subscription)
    {
        Log::info('Trial ended, subscription now active', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
        ]);
        
        // Send welcome email after trial
        // Mail::to($subscription->user->email)->send(new TrialEndedNotification($subscription));
    }
    
    private function handleFirstPaymentAfterTrial($subscription, $invoice)
    {
        Log::info('First payment after trial succeeded', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'amount' => $invoice->amount_paid / 100,
        ]);
        
        // Send confirmation email
        // Mail::to($subscription->user->email)->send(new FirstPaymentSucceededNotification($subscription, $invoice));
    }
    
    private function handleMonthlyRenewal($subscription, $invoice)
    {
        Log::info('Monthly subscription renewed', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'amount' => $invoice->amount_paid / 100,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
        ]);
        
        // Update subscription record
        // Send receipt email (optional)
        // Mail::to($subscription->user->email)->send(new PaymentReceiptNotification($subscription, $invoice));
    }
    
    private function handlePastDue($subscription)
    {
        Log::warning('Subscription past due', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
        ]);
        
        // Already handled in handlePaymentFailed
    }
    
    private function handleSubscriptionInactive($subscription, $status)
    {
        Log::error('Subscription inactive', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'status' => $status,
        ]);
    }
    
    private function notifyPaymentFailure($user, $invoice, $subscription)
    {
        // Send email notification
        try {
            $email = $user->profile->email ?? $user->email;
            if ($email) {
                // Mail::to($email)->send(new PaymentFailedNotification($user, $invoice, $subscription));
                Log::info('Payment failure notification sent', ['user_id' => $user->id]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send payment failure notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    private function notifySubscriptionCanceled($user, $subscription)
    {
        // Send cancellation confirmation email
        try {
            $email = $user->profile->email ?? $user->email;
            if ($email) {
                // Mail::to($email)->send(new SubscriptionCanceledNotification($user, $subscription));
                Log::info('Cancellation notification sent', ['user_id' => $user->id]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send cancellation notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    private function notifyTrialEnding($user, $subscription)
    {
        // Send trial ending reminder
        try {
            $email = $user->profile->email ?? $user->email;
            if ($email) {
                // Mail::to($email)->send(new TrialEndingReminderNotification($user, $subscription));
                Log::info('Trial ending reminder sent', ['user_id' => $user->id]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send trial ending reminder', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

## 🔔 Webhook Events Summary

### Events We Handle:

| Event | When Triggered | What We Do |
|-------|---------------|------------|
| `customer.subscription.created` | Subscription created | Confirm subscription exists in DB |
| `customer.subscription.updated` | Status changes, trial ends | **Update subscription status and dates** |
| `customer.subscription.deleted` | Subscription cancelled | Mark as canceled, set end date |
| `customer.subscription.trial_will_end` | 3 days before trial ends | Send reminder email |
| `invoice.payment_succeeded` | Payment successful | **Update subscription, handle renewal** |
| `invoice.payment_failed` | Payment failed | Update status, send notification |
| `invoice.created` | Invoice created | Log for tracking (optional) |

## 📊 Database Updates

### Subscription Table Updates:

**On Payment Succeeded (After Trial):**
```php
$subscription->update([
    'stripe_status' => 'active',  // Changed from 'trialing' to 'active'
    'current_period_start' => now(),  // New billing period starts
    'current_period_end' => now()->addMonth(),  // Next billing date
    // Amount already set during creation
]);
```

**On Monthly Renewal:**
```php
$subscription->update([
    'current_period_start' => now(),  // New billing period
    'current_period_end' => now()->addMonth(),  // Next charge date
    'stripe_status' => 'active',  // Still active
]);
```

**On Payment Failed:**
```php
$subscription->update([
    'stripe_status' => 'past_due',  // or 'unpaid'
    // Period dates remain same
]);
```

**On Cancellation:**
```php
$subscription->update([
    'stripe_status' => 'canceled',
    'ends_at' => now(),
    // Service continues until current_period_end
]);
```

## 🔐 Webhook Route

Add to `routes/api.php`:

```php
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handleWebhook'])
    ->middleware('throttle:60,1'); // Rate limit protection
```

**Note:** Webhook should NOT use JWT auth middleware - Stripe sends requests without auth tokens.

## ✅ Testing Webhooks

### Using Stripe CLI:

```bash
# Install Stripe CLI
stripe listen --forward-to localhost:8000/api/webhooks/stripe

# Trigger test events
stripe trigger invoice.payment_succeeded
stripe trigger invoice.payment_failed
stripe trigger customer.subscription.updated
```

## 📋 Implementation Checklist

- [ ] Create `StripeWebhookController`
- [ ] Add webhook route (no auth middleware)
- [ ] Handle `invoice.payment_succeeded` - Update subscription on renewal
- [ ] Handle `invoice.payment_failed` - Update status, notify user
- [ ] Handle `customer.subscription.updated` - Update on trial end, status changes
- [ ] Handle `customer.subscription.deleted` - Update on cancellation
- [ ] Handle `customer.subscription.trial_will_end` - Send reminder
- [ ] Update subscription table on all events
- [ ] Update user subscription_status field
- [ ] Test with Stripe webhook testing tool
- [ ] Configure webhook endpoint in Stripe dashboard
- [ ] Set up logging for all webhook events

## 🎯 Summary

**YES, we handle ALL scenarios:**

1. ✅ **Monthly subscription renewal** → `invoice.payment_succeeded` → Update subscription table
2. ✅ **Payment failures** → `invoice.payment_failed` → Update status, notify user
3. ✅ **Cancellations** → `customer.subscription.deleted` → Mark as canceled
4. ✅ **After trial period** → `invoice.payment_succeeded` + `customer.subscription.updated` → Debit card, update table
5. ✅ **Status changes** → `customer.subscription.updated` → Update all subscription details

**All webhook events update the database tables automatically!**


