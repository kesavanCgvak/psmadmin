# Stripe Subscription Integration Guide

## 📋 Overview

This guide outlines the backend integration approach for **Stripe Subscriptions** for both **Providers** and **Users** in your PSM Admin system. The frontend is already implemented, so this focuses on Laravel backend integration.

### 🔴 CRITICAL REQUIREMENT

**Credit card is REQUIRED for BOTH Providers and Users during registration.**
- ❌ No registration without credit card
- ✅ Both account types must provide payment method
- ✅ Card stored in Stripe (secure)
- ✅ Trial periods apply (60 days provider, 14 days user)

## 🎯 Subscription Model

### Account Types:
- **Provider** - Companies/individuals who provide rental equipment
- **User** - Customers who rent equipment

### Subscription Requirements:

#### **PROVIDERS:**
- ✅ **Credit card required** on registration (stored in Stripe)
- ✅ **60 days free trial** - No charge during trial period
- ✅ **$99.00/month** - Charged automatically after 60-day trial ends
- ✅ **Cancel anytime** - Service continues until end of current billing period
- ✅ **No refunds** - Cancellations are immediate but service continues until period ends

#### **USERS:**
- ✅ **Credit card required** on registration (stored in Stripe)
- ✅ **14 days free trial** - No charge during trial period
- ✅ **$2.99/month** - Charged automatically after 14-day trial ends
- ✅ **Cancel anytime** - Service continues until end of current billing period
- ✅ **No refunds** - Cancellations are immediate but service continues until period ends

### Stripe Products:
- Provider Plan (already created in Stripe)
- User Plan (already created in Stripe)

## 🏗️ Architecture Overview

### Subscription Flow:

#### **Provider Registration Flow:**
1. Provider fills registration form + **Credit card via Stripe Elements**
2. **Registration payload includes:**
   - All registration data
   - `payment_method_id` (from Stripe Elements)
   - `billing_details` (name, email, address)
3. **Backend processes:**
   - Creates user account
   - Creates Stripe customer
   - Attaches payment method
   - Creates subscription with 60-day trial
4. **After 60 days** → Stripe automatically charges $99.00/month
5. **Cancel anytime** → Service continues until period end

#### **User Registration Flow:**
1. User fills registration form + **Credit card via Stripe Elements**
2. **Registration payload includes:**
   - All registration data
   - `payment_method_id` (from Stripe Elements) - **REQUIRED**
   - `billing_details` (name, email, address) - **REQUIRED**
3. **Backend processes in one transaction:**
   - Creates user account
   - Creates company
   - Creates Stripe customer
   - Attaches payment method
   - Creates subscription with 14-day trial
4. **After 14 days** → Stripe automatically charges $2.99/month
5. **Cancel anytime** → Service continues until period end
6. **No refunds** - Cancellations continue service until period end

## 📦 Step-by-Step Implementation

### 1. Install Stripe PHP SDK

```bash
composer require stripe/stripe-php
```

### 2. Configure Stripe Keys

Add to `.env`:
```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Update `config/services.php`:
```php
'stripe' => [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
],
```

### 3. Database Schema

Create migration for subscriptions:

```php
// database/migrations/xxxx_create_subscriptions_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            
            // User relationship (can be provider or regular user)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            
            // Account type
            $table->enum('account_type', ['provider', 'user'])->default('user');
            
            // Stripe related
            $table->string('stripe_subscription_id')->unique();
            $table->string('stripe_customer_id')->index();
            $table->string('stripe_price_id');
            $table->string('stripe_status'); // active, canceled, past_due, etc.
            
            // Subscription details
            $table->string('plan_name'); // e.g., "Provider Basic", "User Premium"
            $table->string('plan_type'); // e.g., "basic", "pro", "enterprise"
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('interval'); // month, year
            
            // Dates
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable(); // For canceled subscriptions
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            
            $table->timestamps();
            
            $table->index(['user_id', 'stripe_status']);
            $table->index('stripe_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
```

Update users table to add subscription fields:

```php
// database/migrations/xxxx_add_subscription_fields_to_users.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // stripe_customer_id already exists
            $table->string('subscription_status')->nullable()->after('stripe_customer_id'); // active, canceled, etc.
            $table->timestamp('subscription_ends_at')->nullable()->after('subscription_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['subscription_status', 'subscription_ends_at']);
        });
    }
};
```

**Note:** We do NOT need to add subscription fields to the companies table because:
- Subscriptions are **per user**, not per company
- Each user has their own `stripe_customer_id` (already in users table)
- Subscriptions table links to `user_id` for subscription details
- Company is just organizational grouping - subscription is tied to individual user

See `SUBSCRIPTION_ARCHITECTURE_CLARIFICATION.md` for detailed explanation.

### 4. Create Subscription Model

```php
// app/Models/Subscription.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'account_type',
        'stripe_subscription_id',
        'stripe_customer_id',
        'stripe_price_id',
        'stripe_status',
        'plan_name',
        'plan_type',
        'amount',
        'currency',
        'interval',
        'trial_ends_at',
        'ends_at',
        'current_period_start',
        'current_period_end',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return $this->stripe_status === 'active' || 
               ($this->trial_ends_at && $this->trial_ends_at->isFuture());
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isCanceled(): bool
    {
        return in_array($this->stripe_status, ['canceled', 'unpaid']);
    }
}
```

### 5. Update User Model

Add subscription relationship:

```php
// Add to app/Models/User.php

public function subscription()
{
    return $this->hasOne(Subscription::class)->latestOfMany();
}

public function subscriptions()
{
    return $this->hasMany(Subscription::class);
}

public function hasActiveSubscription(): bool
{
    return $this->subscription && $this->subscription->isActive();
}

// Add to fillable array:
// 'subscription_status', 'subscription_ends_at'
```

### 6. Update Company Model (Optional - Only for Reporting)

**Note:** Since subscriptions are per user (not per company), you typically don't need subscription methods in Company model. However, you can add these if you need to query subscriptions by company for reporting:

```php
// Optional - Only add if needed for reporting/analytics
// Add to app/Models/Company.php

public function subscriptions()
{
    return $this->hasMany(Subscription::class);
}

// Helper to check if ANY user in company has active subscription
public function hasAnyActiveSubscription(): bool
{
    return $this->subscriptions()
        ->whereIn('stripe_status', ['active', 'trialing'])
        ->exists();
}
```

**Remember:** The subscription belongs to the USER, not the company. Use `$user->subscription` to check subscription status.

### 7. Create Subscription Plans Config

```php
// config/subscription_plans.php
<?php

return [
    'provider' => [
        'default' => [
            'name' => 'Provider Plan',
            'stripe_price_id' => env('STRIPE_PRICE_PROVIDER_PLAN', 'price_xxx'), // Your Stripe Provider Plan Price ID
            'amount' => 99.00,
            'currency' => 'USD',
            'interval' => 'month',
            'trial_days' => 60, // 60 days free trial
            'requires_payment_method' => true, // Credit card required on registration
        ],
    ],
    'user' => [
        'default' => [
            'name' => 'User Plan',
            'stripe_price_id' => env('STRIPE_PRICE_USER_PLAN', 'price_xxx'), // Your Stripe User Plan Price ID
            'amount' => 2.99,
            'currency' => 'USD',
            'interval' => 'month',
            'trial_days' => 14, // 14 days free trial
            'requires_payment_method' => true, // Credit card required on registration (same as providers)
        ],
    ],
];
```

**Important:** Replace `price_xxx` with your actual Stripe Price IDs from your Stripe dashboard.

## 🔑 Key API Endpoints

### 1. Registration Endpoint (Updated with Payment Details)

**✅ RECOMMENDED APPROACH:** Include `payment_method_id` and `billing_details` directly in registration payload.

```
POST /api/register

Body for PROVIDER:
{
    // Existing registration fields
    "account_type": "provider",
    "company_name": "ABC Equipment Co.",
    "username": "provider123",
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "region": 1,
    "country_id": 1,
    "state_id": 1,
    "city": 1,
    "mobile": "+1234567890",
    "birthday": "1990-01-01",
    "terms_accepted": true,
    
    // Stripe Payment Details (REQUIRED for Provider)
    "payment_method_id": "pm_1ABC123...",  // From Stripe Elements
    "billing_details": {
        "name": "John Doe",
        "email": "john@example.com",
        "phone": "+1234567890",
        "address": {
            "line1": "123 Main St",
            "line2": "Apt 4B",
            "city": "New York",
            "state": "NY",
            "postal_code": "10001",
            "country": "US"
        }
    }
}

Body for USER:
{
    // Existing registration fields
    "account_type": "user",
    "company_name": "My Company",
    "username": "user123",
    "name": "Jane Doe",
    "email": "jane@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "region": 1,
    "country_id": 1,
    "state_id": 1,
    "city": 1,
    "mobile": "+1234567890",
    "birthday": "1990-01-01",
    "terms_accepted": true,
    
    // Stripe Payment Details (REQUIRED for User)
    "payment_method_id": "pm_1XYZ789...",  // From Stripe Elements - REQUIRED
    "billing_details": {  // REQUIRED
        "name": "Jane Doe",
        "email": "jane@example.com",
        "phone": "+1234567890",
        "address": {
            "line1": "456 Oak Ave",
            "city": "Los Angeles",
            "state": "CA",
            "postal_code": "90001",
            "country": "US"
        }
    }
}

Response:
{
    "status": "success",
    "message": "User registered successfully",
    "user": {
        "id": 1,
        "username": "provider123",
        "account_type": "provider"
    },
    "subscription": {
        "id": 1,
        "status": "trialing",
        "trial_ends_at": "2024-03-01T00:00:00Z",
        "stripe_customer_id": "cus_xxx"
    }
}
```

**Validation Rules:**
```php
// In AuthController::register()

$rules = [
    // Existing validation rules...
    'payment_method_id' => [
        'required',  // Required for both providers and users
        'string',
        'starts_with:pm_',  // Validate Stripe payment method format
    ],
    'billing_details' => [
        'required',  // Required for both providers and users
        'array',
    ],
    'billing_details.name' => 'required_with:billing_details|string|max:255',
    'billing_details.email' => 'required_with:billing_details|email',
    'billing_details.address' => 'required_with:billing_details|array',
    'billing_details.address.line1' => 'required_with:billing_details.address|string',
    'billing_details.address.city' => 'required_with:billing_details.address|string',
    'billing_details.address.state' => 'required_with:billing_details.address|string',
    'billing_details.address.postal_code' => 'required_with:billing_details.address|string',
    'billing_details.address.country' => 'required_with:billing_details.address|string|size:2',
];
```

### 2. Get Current Subscription

```
GET /api/subscriptions/current

Response: {
    "success": true,
    "subscription": {
        "id": 1,
        "plan_name": "Provider Plan",
        "status": "active",
        "amount": 99.00,
        "trial_ends_at": "2024-03-01T00:00:00Z",
        "current_period_end": "2024-02-01T00:00:00Z",
        "is_trialing": false,
        "is_active": true
    }
}
```

### 3. Get Current Subscription

```
GET /api/subscriptions/current

Response: {
    "success": true,
    "subscription": {
        "id": 1,
        "plan_name": "Provider Basic",
        "plan_type": "basic",
        "status": "active",
        "amount": 29.99,
        "current_period_end": "2024-02-01T00:00:00Z",
        "is_active": true
    }
}
```

### 5. Cancel Subscription

```
POST /api/subscriptions/cancel

Response: {
    "success": true,
    "message": "Subscription will be canceled at end of billing period. Service continues until {current_period_end}",
    "cancel_at_period_end": true,
    "current_period_end": "2024-02-01T00:00:00Z"
}
```

**Note:** For both Providers and Users - No refunds. Service continues until period end.

### 6. Webhook Endpoint

```
POST /api/webhooks/stripe
(Handles Stripe subscription events automatically)
```

## 🔔 Webhook Events to Handle

1. **customer.subscription.created** - Subscription created (with trial)
2. **customer.subscription.updated** - Subscription updated (trial ended, renewal, cancellation)
3. **customer.subscription.trial_will_end** - Trial ending soon (send reminder)
4. **customer.subscription.deleted** - Subscription canceled/completed
5. **invoice.payment_succeeded** - Payment successful (after trial ends)
6. **invoice.payment_failed** - Payment failed (handle retry)
7. **customer.subscription.paused** - Subscription paused (if applicable)

**Critical Events:**
- When `customer.subscription.updated` with `status: "active"` and trial ended → First charge happened
- When `invoice.payment_succeeded` → Monthly charge successful
- When `invoice.payment_failed` → **Payment failed after trial (see PAYMENT_FAILURE_HANDLING.md for details)**
- When `customer.subscription.updated` with `status: "past_due"` → Payment failed, in grace period
- When `customer.subscription.updated` with `status: "unpaid"` → Payment failed, subscription inactive
- When `customer.subscription.deleted` → Subscription ended (at period end for cancellations)

**⚠️ CRITICAL SCENARIO:** Payment failure after trial ends - See `PAYMENT_FAILURE_HANDLING.md` for complete implementation guide.

## 💡 Implementation Structure

### SubscriptionController Methods:

1. **setupProviderPayment()** - Save credit card for provider (required on registration)
2. **createProviderSubscription()** - Create provider subscription with 60-day trial
3. **createUserSubscription()** - Create user subscription with 14-day trial
4. **getCurrentSubscription()** - Get user's current subscription status
5. **cancelSubscription()** - Cancel subscription (continues until period end)
6. **updatePaymentMethod()** - Update saved payment method

### SubscriptionService Class:

Helper service for Stripe operations:

```php
// app/Services/StripeSubscriptionService.php
- createCustomer() - Create Stripe customer
- attachPaymentMethod() - Attach card to customer (for providers)
- createSubscriptionWithTrial() - Create subscription with trial period
  - Provider: 60 days trial, $99/month after
  - User: 14 days trial, $2.99/month after
- cancelSubscription() - Cancel at period end
- syncSubscriptionFromStripe() - Sync subscription status from webhook
- getUpcomingInvoice() - Get next billing amount/date
```

### Key Implementation Details:

**Provider Registration:**
```php
// 1. Create Stripe Customer
// 2. Attach Payment Method (required)
// 3. Create Subscription with:
//    - trial_period_days: 60
//    - price: Provider Plan Price ID
//    - default_payment_method: attached payment method
```

**User Registration:**
```php
// 1. Create Stripe Customer
// 2. Attach Payment Method (required)
// 3. Create Subscription with:
//    - trial_period_days: 14
//    - price: User Plan Price ID
//    - default_payment_method: attached payment method
```

## 🔐 Subscription Status Check on Login

**Approach: Allow login but include subscription status in response (Option 1)**

Users can login, but the response includes their subscription status so the frontend can handle access control.

### Update Login Controller

```php
// app/Http/Controllers/Api/AuthController.php

public function login(Request $request)
{
    // ... existing validation and authentication code ...
    
    $user = JWTAuth::user();
    
    // Existing checks...
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
        'subscription', // Load subscription
    ]);
    
    // Build subscription status response
    $subscriptionStatus = null;
    $subscription = $user->subscription;
    
    if ($subscription) {
        $subscriptionStatus = [
            'has_subscription' => true,
            'is_active' => $subscription->isActive(),
            'is_trialing' => $subscription->isOnTrial(),
            'status' => $subscription->stripe_status,
            'trial_ends_at' => $subscription->trial_ends_at?->format('c'),
            'current_period_end' => $subscription->current_period_end?->format('c'),
            'plan_name' => $subscription->plan_name,
            'amount' => (float) $subscription->amount,
            'currency' => $subscription->currency,
        ];
    } else {
        // User registered but no subscription found
        $subscriptionStatus = [
            'has_subscription' => false,
            'is_active' => false,
            'is_trialing' => false,
            'status' => 'none',
            'message' => 'No subscription found. Please contact support.',
        ];
    }
    
    return response()->json([
        'token' => $token,
        'message' => 'Login successful',
        'user_id' => $user->id,
        'user' => new UserResource($user),
        'subscription' => $subscriptionStatus, // Include subscription status
        'expires_in' => JWTAuth::factory()->getTTL() * 60,
    ]);
}
```

### Login Response Format

```json
{
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "message": "Login successful",
    "user_id": 1,
    "user": { ... },
    "subscription": {
        "has_subscription": true,
        "is_active": true,
        "is_trialing": true,
        "status": "trialing",
        "trial_ends_at": "2024-03-01T00:00:00+00:00",
        "current_period_end": "2024-03-01T00:00:00+00:00",
        "plan_name": "Provider Plan",
        "amount": 99.00,
        "currency": "USD"
    },
    "expires_in": 3600
}
```

**Note:** Users can login even if subscription is expired/canceled. The frontend should check `subscription.is_active` or `subscription.is_trialing` to control access to features.

## 🔐 Access Control Middleware

Create middleware to check subscription status:

```php
// app/Http/Middleware/RequireSubscription.php

public function handle($request, Closure $next, $accountType = null)
{
    $user = auth('api')->user();
    
    if (!$user->hasActiveSubscription()) {
        return response()->json([
            'success' => false,
            'message' => 'Active subscription required',
            'requires_subscription' => true
        ], 403);
    }
    
    return $next($request);
}
```

## 📝 Integration Checklist

- [ ] Install Stripe PHP SDK
- [ ] Add Stripe keys to `.env` and `config/services.php`
- [ ] Add Stripe Price IDs for Provider and User plans to `.env`
- [ ] Create subscriptions migration
- [ ] Update users and companies tables
- [ ] Create Subscription model
- [ ] Create subscription plans config with correct amounts ($99 provider, $2.99 user)
- [ ] Create SubscriptionController
- [ ] Create StripeSubscriptionService
- [ ] Add subscription routes
- [ ] Implement webhook handler (critical for trial end and billing)
- [ ] Update registration flow:
  - [ ] Provider: Require credit card on registration
  - [ ] Provider: Create subscription with 60-day trial
  - [ ] User: Create subscription with 14-day trial
- [ ] Update login controller to include subscription status
- [ ] Load subscription relationship in login
- [ ] Create subscription middleware
- [ ] Add subscription checks to protected routes
- [ ] Test with Stripe test mode
- [ ] Set up Stripe webhook endpoint
- [ ] Test trial periods (60 days provider, 14 days user)
- [ ] Test automatic billing after trial
- [ ] Test cancellation (service continues until period end)

## 🎯 Implementation Summary

### Key Requirements Recap:

**PROVIDERS:**
- ✅ Credit card **required** on registration
- ✅ 60 days free trial
- ✅ $99.00/month after trial
- ✅ Cancel anytime, service continues until period end
- ✅ No refunds

**USERS:**
- ✅ $2.99/month
- ✅ 14 days free trial
- ✅ Auto-charge after trial

**Stripe Products:**
- Provider Plan (already created - use Price ID)
- User Plan (already created - use Price ID)

## 🎯 Next Steps

Would you like me to implement:

1. ✅ **Subscription Model & Migration** - Database structure
2. ✅ **SubscriptionController** - All API endpoints (provider/user specific)
3. ✅ **StripeSubscriptionService** - Stripe integration with trial periods
4. ✅ **Webhook Handler** - Handle trial end, billing, cancellations
5. ✅ **Update Registration Flow** - Integrate subscription creation
6. ✅ **Middleware** - Access control based on subscription status
7. ✅ **Update existing models** - Add subscription relationships

Let me know which parts you'd like me to implement first!

