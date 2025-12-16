# Subscription Status Check on Login

## 🔍 Current Status: **NOT Checking Subscription on Login**

Looking at the current login implementation (`AuthController::login()`), it currently checks:
- ✅ Username/password validation
- ✅ Account blocked status
- ✅ Email verification

But it does **NOT** check:
- ❌ Subscription status
- ❌ Payment status
- ❌ Active subscription requirement

## 💡 Recommendation: Two Approaches

You have **two options** for handling subscription checks:

### **Option 1: Check on Login + Allow Login (Recommended)**

**Allow login but include subscription status in response** - User can login but frontend handles access based on subscription status.

**Pros:**
- User can see their subscription status
- Frontend can show appropriate messages/redirects
- Better UX - user knows why they can't access features

**Cons:**
- Requires frontend to handle subscription status
- User might be confused if they can login but can't access features

### **Option 2: Check on Login + Block Login**

**Block login if no active subscription** - User cannot login without active subscription.

**Pros:**
- Clear enforcement - no login without subscription
- Simple - subscription check at entry point

**Cons:**
- Harsh UX - user can't even see their account
- Can't access account to update payment method
- Harder to handle trial periods gracefully

## ✅ Recommended Implementation: Option 1

### Update Login Controller

```php
// app/Http/Controllers/Api/AuthController.php

public function login(Request $request)
{
    // ... existing validation and authentication code ...
    
    $user = JWTAuth::user();
    
    // Existing checks...
    if ($user->is_blocked) {
        // ... handle blocked account
    }
    
    if (!$user->email_verified) {
        // ... handle unverified email
    }
    
    // NEW: Load subscription information
    $user->load(['subscription']);
    
    // Check subscription status
    $subscriptionStatus = null;
    $subscription = $user->subscription;
    
    if ($subscription) {
        $subscriptionStatus = [
            'has_subscription' => true,
            'is_active' => $subscription->isActive(),
            'is_trialing' => $subscription->isOnTrial(),
            'status' => $subscription->stripe_status,
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'current_period_end' => $subscription->current_period_end?->toISOString(),
            'plan_name' => $subscription->plan_name,
            'amount' => $subscription->amount,
        ];
    } else {
        // User registered but no subscription found (shouldn't happen but handle it)
        $subscriptionStatus = [
            'has_subscription' => false,
            'is_active' => false,
            'status' => 'none',
            'message' => 'No subscription found. Please contact support.',
        ];
    }
    
    return response()->json([
        'token' => $token,
        'message' => 'Login successful',
        'user_id' => $user->id,
        'user' => new UserResource($user),
        'subscription' => $subscriptionStatus,  // Include subscription status
        'expires_in' => JWTAuth::factory()->getTTL() * 60,
    ]);
}
```

### Frontend Handling

```javascript
// Frontend after login
const response = await login(username, password);

if (response.subscription) {
    if (!response.subscription.is_active && !response.subscription.is_trialing) {
        // Redirect to subscription/payment page
        redirect('/subscription/payment-required');
    } else if (response.subscription.is_trialing) {
        // Show trial countdown
        showTrialBanner(response.subscription.trial_ends_at);
    }
}
```

## 🔐 Route Protection with Middleware

Even if you allow login, **protect routes** that require active subscription:

```php
// routes/api.php

Route::middleware(['jwt.verify', 'require.subscription'])->group(function () {
    // Protected routes that require active subscription
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/rental-requests', [RentalRequestController::class, 'store']);
    // ... other protected routes
});

// Routes that don't require subscription (profile, subscription management)
Route::middleware(['jwt.verify'])->group(function () {
    Route::get('/user/profile', [UserProfileController::class, 'getProfile']);
    Route::get('/subscriptions/current', [SubscriptionController::class, 'getCurrent']);
    Route::post('/subscriptions/update-payment', [SubscriptionController::class, 'updatePaymentMethod']);
});
```

## 📋 Implementation Checklist

- [ ] Update `AuthController::login()` to load subscription
- [ ] Include subscription status in login response
- [ ] Create `RequireSubscription` middleware
- [ ] Apply middleware to protected routes
- [ ] Update frontend to handle subscription status
- [ ] Test with active subscription
- [ ] Test with expired/canceled subscription
- [ ] Test with trial subscription

## 🎯 Alternative: Block Login (Option 2)

If you want to **block login** without active subscription:

```php
public function login(Request $request)
{
    // ... authentication code ...
    
    $user = JWTAuth::user();
    
    // Existing checks...
    if ($user->is_blocked) { /* ... */ }
    if (!$user->email_verified) { /* ... */ }
    
    // NEW: Check subscription
    $user->load(['subscription']);
    
    if (!$user->subscription || !$user->subscription->isActive()) {
        auth()->logout();
        
        return response()->json([
            'status' => 'error',
            'message' => 'Active subscription required. Please subscribe to continue.',
            'requires_subscription' => true,
            'subscription_status' => $user->subscription?->stripe_status ?? 'none',
        ], 403);
    }
    
    // Continue with login...
}
```

## ❓ Which Approach to Use?

**Recommendation:** Use **Option 1** (allow login, include status) because:
1. Better UX - user can see their subscription status
2. User can update payment method if needed
3. Frontend can show appropriate messages
4. More flexible - handle edge cases better

**Use Option 2** (block login) if:
- You want strict enforcement
- You don't want users accessing the app at all without subscription
- Simpler enforcement model

---

**Question for you:** Which approach would you prefer?
1. Allow login, check subscription status in response
2. Block login if no active subscription


