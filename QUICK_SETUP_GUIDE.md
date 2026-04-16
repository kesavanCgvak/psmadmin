# Quick Setup Guide - Stripe Subscriptions

## 🚀 Quick Start (5 Steps)

### 1. Install Stripe PHP SDK
```bash
composer require stripe/stripe-php
```

### 2. Add to `.env` file:
```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_PROVIDER_PLAN=price_xxx  # Your Provider Plan Price ID
STRIPE_PRICE_USER_PLAN=price_xxx      # Your User Plan Price ID
```

### 3. Run Migrations
```bash
php artisan migrate
```

### 4. Configure Webhook in Stripe Dashboard
- Go to Stripe Dashboard → Webhooks
- Add endpoint: `https://yourdomain.com/api/webhooks/stripe`
- Select events:
  - `customer.subscription.created`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
  - `customer.subscription.trial_will_end`
  - `invoice.payment_succeeded`
  - `invoice.payment_failed`

### 5. Test!

## ✅ That's It!

All code is implemented. Just add your Stripe keys and Price IDs!

## 📝 Testing

### Test Registration:
```json
POST /api/register
{
    "account_type": "provider",
    "company_name": "Test Co",
    "username": "testuser",
    "email": "test@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "payment_method_id": "pm_test_xxx",
    "billing_details": { ... },
    ...
}
```

### Test Login Response:
```json
{
    "token": "...",
    "user": { ... },
    "subscription": {
        "has_subscription": true,
        "is_active": true,
        "is_trialing": true,
        "status": "trialing",
        ...
    }
}
```

## 📚 Full Documentation

See `STRIPE_IMPLEMENTATION_COMPLETE.md` for complete details.


