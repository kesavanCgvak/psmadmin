# How to Get STRIPE_WEBHOOK_SECRET

## 🔐 What is STRIPE_WEBHOOK_SECRET?

`STRIPE_WEBHOOK_SECRET` is a **signing secret** provided by Stripe for each webhook endpoint. It's used to:

- ✅ **Verify** that webhook requests are actually coming from Stripe
- ✅ **Prevent** unauthorized/fake webhook requests
- ✅ **Ensure** the webhook payload hasn't been tampered with

## 📍 Where is it Used?

In `app/Http/Controllers/Api/StripeWebhookController.php`, the secret is used to verify webhook signatures:

```php
$event = Webhook::constructEvent(
    $payload,
    $sigHeader,
    config('services.stripe.webhook_secret')  // ← Uses STRIPE_WEBHOOK_SECRET
);
```

## 🔧 How to Get It

### **Method 1: From Stripe Dashboard (Production/Testing)**

1. **Go to Stripe Dashboard:**
   - Test Mode: https://dashboard.stripe.com/test/webhooks
   - Live Mode: https://dashboard.stripe.com/webhooks

2. **Click "+ Add endpoint"**

3. **Enter your webhook URL:**
   ```
   https://yourdomain.com/api/webhooks/stripe
   ```

4. **Select events to listen to:**
   - ✅ `customer.subscription.created`
   - ✅ `customer.subscription.updated`
   - ✅ `customer.subscription.deleted`
   - ✅ `customer.subscription.trial_will_end`
   - ✅ `invoice.payment_succeeded`
   - ✅ `invoice.payment_failed`

5. **Click "Add endpoint"**

6. **Copy the Signing secret:**
   - After creating the endpoint, you'll see a **"Signing secret"** starting with `whsec_...`
   - Click "Reveal" or "Click to reveal" to see the full secret
   - Copy this value

7. **Add to your `.env` file:**
   ```env
   STRIPE_WEBHOOK_SECRET=whsec_your_actual_secret_here
   ```

### **Method 2: Stripe CLI (Local Development)**

For local development, use Stripe CLI:

1. **Install Stripe CLI:**
   - Download from: https://stripe.com/docs/stripe-cli
   - Or install via package manager

2. **Login to Stripe CLI:**
   ```bash
   stripe login
   ```

3. **Forward webhooks to your local server:**
   ```bash
   stripe listen --forward-to localhost:8000/api/webhooks/stripe
   ```

4. **Copy the webhook secret:**
   - The CLI will display a webhook signing secret like:
     ```
     > Ready! Your webhook signing secret is whsec_xxxxxxxxxxxxx
     ```
   - Copy this secret and add it to your `.env` file

5. **Trigger test events:**
   ```bash
   stripe trigger customer.subscription.created
   ```

### **Method 3: Using ngrok (Alternative for Local Testing)**

1. **Install ngrok:**
   ```bash
   # Download from https://ngrok.com/download
   ```

2. **Start your Laravel server:**
   ```bash
   php artisan serve
   ```

3. **Start ngrok:**
   ```bash
   ngrok http 8000
   ```

4. **Copy the ngrok URL** (e.g., `https://abc123.ngrok.io`)

5. **Create webhook endpoint in Stripe Dashboard:**
   - URL: `https://abc123.ngrok.io/api/webhooks/stripe`
   - Select all required events
   - Copy the signing secret

6. **Add to `.env`:**
   ```env
   STRIPE_WEBHOOK_SECRET=whsec_your_secret_from_stripe
   ```

## ⚠️ Important Notes

1. **Different Secrets for Test/Live:**
   - Test mode: `whsec_test_...` or just `whsec_...` (test webhooks)
   - Live mode: `whsec_live_...` (live webhooks)

2. **Each Endpoint Has Unique Secret:**
   - If you create multiple webhook endpoints, each has its own secret
   - Make sure you use the correct secret for the correct endpoint

3. **Keep It Secret:**
   - Never commit this to version control
   - Always use environment variables
   - Treat it like an API key

4. **After Changing Secrets:**
   - Restart your Laravel application if using `php artisan serve`
   - Or run `php artisan config:clear` to refresh config cache

## ✅ Verification

After adding the secret, you can verify it's working:

1. **Check logs:**
   - When Stripe sends a webhook, check `storage/logs/laravel.log`
   - You should see: `Stripe webhook received` with event type and ID

2. **Test with Stripe CLI:**
   ```bash
   stripe trigger customer.subscription.created
   ```
   - Check your logs to see if the webhook was received and processed

3. **Check for errors:**
   - If you see "Invalid signature" errors, the secret is wrong
   - Double-check the secret in your `.env` file matches the one in Stripe Dashboard

## 🔄 Updating the Secret

If you need to update the webhook secret:

1. Go to Stripe Dashboard → Webhooks
2. Click on your webhook endpoint
3. Click "Reveal" or "Reveal test key" next to "Signing secret"
4. Copy the new secret
5. Update your `.env` file
6. Clear config cache: `php artisan config:clear`
7. Restart your server (if needed)

## 📝 Quick Checklist

- [ ] Created webhook endpoint in Stripe Dashboard
- [ ] Selected all required events
- [ ] Copied the signing secret (`whsec_...`)
- [ ] Added to `.env` as `STRIPE_WEBHOOK_SECRET`
- [ ] Verified config is loaded (check `config/services.php`)
- [ ] Tested webhook with a test event

---

**Need Help?** Check the Stripe documentation: https://stripe.com/docs/webhooks/signatures


