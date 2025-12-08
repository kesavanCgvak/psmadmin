# Required Stripe Webhook Events

## ✅ All Events You Need to Select

When configuring your Stripe webhook endpoint, select these **6 events**:

### 📋 Complete List

1. **`customer.subscription.created`**
   - **When:** A new subscription is created (during registration)
   - **What it does:** Updates subscription status in database

2. **`customer.subscription.updated`**
   - **When:** Subscription status changes (trial ends, renewal, cancellation, payment failures)
   - **What it does:** Updates subscription status, periods, and trial dates
   - **Critical for:**
     - Trial period ending → Active status
     - Payment failures → Past due/Unpaid status
     - Cancellation changes
     - Any subscription modification

3. **`customer.subscription.deleted`**
   - **When:** Subscription is permanently canceled/deleted
   - **What it does:** Marks subscription as canceled, sets `ends_at` date
   - **Note:** For cancellations, service continues until period end

4. **`customer.subscription.trial_will_end`**
   - **When:** 3 days before trial period ends
   - **What it does:** Logs event (you can send reminder emails)
   - **Use case:** Notify users trial is ending soon

5. **`invoice.payment_succeeded`**
   - **When:** Successful payment (monthly charges after trial)
   - **What it does:** Updates subscription status to active, records payment
   - **Triggers:**
     - First payment after trial ends
     - Monthly recurring payments
     - Payment retry after failure

6. **`invoice.payment_failed`**
   - **When:** Payment attempt fails
   - **What it does:** Updates subscription status, logs failure
   - **Critical for:** Handling failed payments after trial period

---

## 🎯 Quick Copy-Paste List for Stripe Dashboard

When adding events in Stripe Dashboard, search for and select these exact names:

```
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
customer.subscription.trial_will_end
invoice.payment_succeeded
invoice.payment_failed
```

---

## 📊 Event Scenarios Covered

### **Registration Flow:**
- User registers → `customer.subscription.created` fires

### **Trial Period:**
- Trial ending soon → `customer.subscription.trial_will_end` fires (3 days before)
- Trial ends → `customer.subscription.updated` (status: trialing → active)
- First charge → `invoice.payment_succeeded` fires

### **Monthly Renewals:**
- Every month → `invoice.payment_succeeded` fires
- Status updated → `customer.subscription.updated` fires

### **Payment Failures:**
- Payment fails → `invoice.payment_failed` fires
- Status changes → `customer.subscription.updated` (status: past_due/unpaid)

### **Cancellations:**
- User cancels → `customer.subscription.updated` (cancel_at_period_end: true)
- Period ends → `customer.subscription.deleted` fires
- Service stops at period end (no refunds)

---

## 🔍 How to Configure in Stripe Dashboard

### Step-by-Step:

1. **Go to Stripe Dashboard:**
   - Test Mode: https://dashboard.stripe.com/test/webhooks
   - Live Mode: https://dashboard.stripe.com/webhooks

2. **Click "+ Add endpoint"**

3. **Enter your webhook URL:**
   ```
   https://yourdomain.com/api/webhooks/stripe
   ```

4. **Click "Select events"**

5. **Search and select these 6 events:**
   - Type "customer.subscription.created" → Select it
   - Type "customer.subscription.updated" → Select it
   - Type "customer.subscription.deleted" → Select it
   - Type "customer.subscription.trial_will_end" → Select it
   - Type "invoice.payment_succeeded" → Select it
   - Type "invoice.payment_failed" → Select it

   **OR** use the quick filter:
   - Filter by "Customer subscriptions" → Select all subscription events
   - Filter by "Invoices" → Select payment succeeded and failed

6. **Click "Add endpoint"**

7. **Copy the Signing secret** (starts with `whsec_...`)

8. **Add to your `.env` file:**
   ```env
   STRIPE_WEBHOOK_SECRET=whsec_your_secret_here
   ```

---

## 🚫 Events NOT Needed (Optional)

These events are **not required** but you can add them if needed:

- `customer.subscription.paused` - If you implement subscription pausing
- `invoice.created` - If you need to track invoice creation
- `customer.created` - If you need to track customer creation
- `payment_method.attached` - If you need to track payment method changes

**Note:** The current implementation handles all subscription scenarios with the 6 required events above.

---

## ✅ Verification

After setting up webhooks:

1. **Check your webhook endpoint is receiving events:**
   - Go to Stripe Dashboard → Webhooks → Your endpoint
   - Check "Recent events" tab
   - You should see events being received

2. **Test with Stripe CLI (local development):**
   ```bash
   stripe trigger customer.subscription.created
   stripe trigger invoice.payment_succeeded
   ```

3. **Check Laravel logs:**
   - Look for `Stripe webhook received` messages in `storage/logs/laravel.log`
   - Verify events are being processed

---

## 📝 Code Reference

All these events are handled in:
- `app/Http/Controllers/Api/StripeWebhookController.php`

See the implementation for details on how each event is processed.

---

**Summary:** You need exactly **6 events** to handle all subscription scenarios including trials, renewals, failures, and cancellations.


