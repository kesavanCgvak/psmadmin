# Payment Failure Handling After Trial

## 🚨 Critical Scenario

**User subscribed → Trial ended → Payment failed (no money in card)**

## 📋 What Happens

### Stripe Flow:
1. Trial ends (14 days for users, 60 days for providers)
2. Stripe automatically attempts to charge the card
3. Payment fails (insufficient funds, card declined, etc.)
4. Stripe sends `invoice.payment_failed` webhook event
5. Subscription status changes to `past_due` or `unpaid`
6. Stripe will retry payment automatically (configured in Stripe dashboard)

## 🔔 Stripe Behavior

Stripe automatically retries failed payments:
- **Default:** Stripe retries 3 times over ~10 days
- **After retries exhausted:** Subscription status becomes `unpaid` or `canceled`
- **Grace period:** You control access during this period

## ✅ Recommended Handling Strategy

### Option 1: Grace Period (Recommended)

**Allow access during grace period, then restrict after retries fail**

1. **Payment fails** → Subscription status: `past_due`
   - ✅ User can still login
   - ✅ User can still access features (grace period)
   - ⚠️ Show payment failure warning
   - ⚠️ Send email notification
   - ⚠️ Allow user to update payment method

2. **After retries fail** → Subscription status: `unpaid` or `canceled`
   - ❌ User login allowed (Option 1 approach)
   - ❌ Restrict access to features
   - ⚠️ Show payment required message
   - ⚠️ Redirect to payment page
   - ⚠️ Allow user to update payment method and retry

3. **After X days of unpaid** → Fully suspend
   - ❌ Can only access subscription management
   - ⚠️ Show account suspended message

### Option 2: Immediate Restriction

**Restrict access immediately on first payment failure**

1. **Payment fails** → Subscription status: `past_due`
   - ❌ Restrict feature access immediately
   - ⚠️ Show payment required
   - ✅ Allow update payment method

## 🔧 Implementation

### 1. Webhook Handler for Payment Failure

```php
// app/Http/Controllers/Api/StripeWebhookController.php

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
    } catch (\Exception $e) {
        return response()->json(['error' => 'Invalid signature'], 400);
    }
    
    switch ($event->type) {
        case 'invoice.payment_failed':
            $this->handlePaymentFailed($event->data->object);
            break;
            
        case 'invoice.payment_succeeded':
            $this->handlePaymentSucceeded($event->data->object);
            break;
            
        case 'customer.subscription.updated':
            $this->handleSubscriptionUpdated($event->data->object);
            break;
            
        // ... other events
    }
    
    return response()->json(['received' => true]);
}

private function handlePaymentFailed($invoice)
{
    // Get subscription from invoice
    $stripeSubscriptionId = $invoice->subscription;
    
    if (!$stripeSubscriptionId) {
        return;
    }
    
    // Find subscription in database
    $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)
        ->with('user')
        ->first();
    
    if (!$subscription) {
        \Log::error('Subscription not found for payment failure', [
            'stripe_subscription_id' => $stripeSubscriptionId
        ]);
        return;
    }
    
    // Get subscription from Stripe to get current status
    $stripeSubscription = \Stripe\Subscription::retrieve($stripeSubscriptionId);
    
    // Update subscription status
    $subscription->update([
        'stripe_status' => $stripeSubscription->status, // past_due, unpaid, etc.
    ]);
    
    // Update user subscription status
    $subscription->user->update([
        'subscription_status' => $stripeSubscription->status,
    ]);
    
    // Send notification to user
    $this->notifyPaymentFailure($subscription->user, $invoice, $stripeSubscription);
    
    // Log the failure
    \Log::warning('Payment failed for subscription', [
        'user_id' => $subscription->user_id,
        'subscription_id' => $subscription->id,
        'stripe_subscription_id' => $stripeSubscriptionId,
        'status' => $stripeSubscription->status,
        'invoice_id' => $invoice->id,
        'amount' => $invoice->amount_due,
        'attempt_count' => $invoice->attempt_count ?? 1,
    ]);
}

private function notifyPaymentFailure($user, $invoice, $subscription)
{
    // Send email notification
    Mail::to($user->profile->email ?? $user->email)->send(
        new PaymentFailedNotification($user, $invoice, $subscription)
    );
    
    // You can also send in-app notification
    // Notification::send($user, new PaymentFailedNotification(...));
}
```

### 2. Handle Subscription Status Updates

```php
private function handleSubscriptionUpdated($stripeSubscription)
{
    $subscription = Subscription::where('stripe_subscription_id', $stripeSubscription->id)
        ->with('user')
        ->first();
    
    if (!$subscription) {
        return;
    }
    
    $oldStatus = $subscription->stripe_status;
    $newStatus = $stripeSubscription->status;
    
    // Update subscription
    $subscription->update([
        'stripe_status' => $newStatus,
        'current_period_start' => now()->setTimestamp($stripeSubscription->current_period_start),
        'current_period_end' => now()->setTimestamp($stripeSubscription->current_period_end),
    ]);
    
    // Update user subscription status
    $subscription->user->update([
        'subscription_status' => $newStatus,
    ]);
    
    // Handle status changes
    if ($oldStatus === 'trialing' && $newStatus === 'active') {
        // Trial ended, payment succeeded
        \Log::info('Trial ended, subscription now active', [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
        ]);
    } elseif ($newStatus === 'past_due') {
        // Payment failed, in grace period
        \Log::warning('Subscription past due', [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
        ]);
    } elseif (in_array($newStatus, ['unpaid', 'canceled'])) {
        // Payment failed, subscription inactive
        \Log::error('Subscription unpaid/canceled', [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'status' => $newStatus,
        ]);
        
        // Notify user that subscription is about to be canceled
        $this->notifySubscriptionUnpaid($subscription->user, $subscription);
    }
}
```

### 3. Update Subscription Model

Add method to check payment failure status:

```php
// app/Models/Subscription.php

public function isPaymentFailed(): bool
{
    return in_array($this->stripe_status, ['past_due', 'unpaid']);
}

public function isPastDue(): bool
{
    return $this->stripe_status === 'past_due';
}

public function isUnpaid(): bool
{
    return $this->stripe_status === 'unpaid';
}

// Update isActive() method
public function isActive(): bool
{
    // Active only if status is active or trialing
    // NOT active if past_due or unpaid
    return in_array($this->stripe_status, ['active', 'trialing']);
}
```

### 4. Update Login Response

Include payment failure information:

```php
// In AuthController::login()

$subscriptionStatus = [
    'has_subscription' => true,
    'is_active' => $subscription->isActive(),
    'is_trialing' => $subscription->isOnTrial(),
    'is_payment_failed' => $subscription->isPaymentFailed(), // Add this
    'is_past_due' => $subscription->isPastDue(), // Add this
    'status' => $subscription->stripe_status,
    'plan_name' => $subscription->plan_name,
    'amount' => $subscription->amount,
    'payment_required' => $subscription->isPaymentFailed(), // Add this
];
```

### 5. Update Payment Method Endpoint

Allow user to update payment method after failure:

```php
// app/Http/Controllers/Api/SubscriptionController.php

public function updatePaymentMethod(Request $request)
{
    $user = auth('api')->user();
    
    $validated = $request->validate([
        'payment_method_id' => 'required|string|starts_with:pm_',
    ]);
    
    $subscription = $user->subscription;
    
    if (!$subscription) {
        return response()->json([
            'success' => false,
            'message' => 'No subscription found'
        ], 404);
    }
    
    try {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
        
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
            // Get the latest invoice
            $invoices = \Stripe\Invoice::all([
                'customer' => $user->stripe_customer_id,
                'subscription' => $subscription->stripe_subscription_id,
                'status' => 'open',
                'limit' => 1,
            ]);
            
            if (!empty($invoices->data)) {
                $invoice = $invoices->data[0];
                
                // Pay the invoice with the new payment method
                $invoice->pay([
                    'payment_method' => $validated['payment_method_id']
                ]);
                
                // Sync subscription status
                $stripeSubscription = \Stripe\Subscription::retrieve($subscription->stripe_subscription_id);
                $subscription->update([
                    'stripe_status' => $stripeSubscription->status,
                ]);
            }
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Payment method updated successfully'
        ]);
        
    } catch (\Stripe\Exception\CardException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Card was declined: ' . $e->getMessage()
        ], 400);
    } catch (\Exception $e) {
        \Log::error('Failed to update payment method', [
            'user_id' => $user->id,
            'error' => $e->getMessage()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to update payment method'
        ], 500);
    }
}
```

### 6. Middleware to Handle Payment Failures

```php
// app/Http/Middleware/RequireSubscription.php

public function handle($request, Closure $next)
{
    $user = auth('api')->user();
    
    if (!$user->subscription) {
        return response()->json([
            'success' => false,
            'message' => 'No subscription found',
            'requires_subscription' => true
        ], 403);
    }
    
    $subscription = $user->subscription;
    
    // Allow access if active or trialing
    if ($subscription->isActive() || $subscription->isOnTrial()) {
        return $next($request);
    }
    
    // Handle payment failure cases
    if ($subscription->isPastDue()) {
        // Grace period - allow access but show warning
        return response()->json([
            'success' => false,
            'message' => 'Payment failed. Please update your payment method to continue.',
            'payment_required' => true,
            'subscription_status' => 'past_due',
            'allow_access' => true, // Optional: allow limited access
        ], 402); // 402 Payment Required
    }
    
    if ($subscription->isUnpaid()) {
        // Payment failed, restrict access
        return response()->json([
            'success' => false,
            'message' => 'Subscription payment required. Please update your payment method.',
            'payment_required' => true,
            'subscription_status' => 'unpaid',
        ], 402);
    }
    
    // Other inactive statuses
    return response()->json([
        'success' => false,
        'message' => 'Active subscription required',
        'subscription_status' => $subscription->stripe_status,
    ], 403);
}
```

## 📧 Email Notifications

### Payment Failed Email Template

```php
// app/Mail/PaymentFailedNotification.php

class PaymentFailedNotification extends Mailable
{
    public function __construct(
        public User $user,
        public $invoice,
        public $subscription
    ) {}
    
    public function build()
    {
        return $this->subject('Payment Failed - Action Required')
            ->view('emails.payment-failed', [
                'user' => $this->user,
                'invoice' => $this->invoice,
                'subscription' => $this->subscription,
                'amount' => number_format($this->invoice->amount_due / 100, 2),
                'update_payment_url' => env('APP_FRONTEND_URL') . '/subscription/update-payment',
            ]);
    }
}
```

## 📋 Decision Points

### 1. Grace Period Access

**Question:** Should users have access during `past_due` status?

**Options:**
- **A)** Full access during grace period (recommended for better UX)
- **B)** Limited access during grace period
- **C)** No access, payment required immediately

**Recommendation:** **Option A** - Full access but show prominent warning

### 2. Retry Payment After Update

**Question:** Should payment be retried automatically when user updates payment method?

**Recommendation:** **YES** - Automatically retry paying the open invoice

### 3. How Long Before Full Suspension?

**Question:** How many days of unpaid status before fully restricting access?

**Options:**
- 0 days - Restrict immediately
- 3-7 days - Short grace period
- 10+ days - Extended grace period

**Recommendation:** **3-7 days** - Balance between user experience and payment collection

## ✅ Implementation Checklist

- [ ] Create webhook handler for `invoice.payment_failed`
- [ ] Update subscription status on payment failure
- [ ] Send email notification on payment failure
- [ ] Create endpoint to update payment method
- [ ] Implement automatic retry on payment method update
- [ ] Update middleware to handle `past_due` and `unpaid` statuses
- [ ] Create email template for payment failure
- [ ] Add payment failure info to login response
- [ ] Update frontend to show payment failure warnings
- [ ] Test payment failure scenario with Stripe test cards
- [ ] Test payment recovery (update card and retry)

## 🎯 Summary

**When payment fails after trial:**

1. ✅ Webhook receives `invoice.payment_failed` event
2. ✅ Update subscription status to `past_due` or `unpaid`
3. ✅ Send email notification to user
4. ✅ Allow user to login (Option 1 approach)
5. ✅ Show payment failure warning in app
6. ✅ Allow user to update payment method
7. ✅ Automatically retry payment with new method
8. ✅ Restrict access if payment continues to fail after grace period


