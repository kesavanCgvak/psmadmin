# Credit Card Requirement Summary

## ✅ CREDIT CARD REQUIRED FOR BOTH PROVIDERS AND USERS

### 🔴 IMPORTANT: Credit Card is MANDATORY for Registration

Both **Providers** and **Users** must provide a credit card during registration.

---

## 📋 Requirements

### **PROVIDERS:**
- ✅ **Credit card REQUIRED** on registration
- ✅ Card stored in Stripe
- ✅ 60 days free trial
- ✅ $99.00/month after trial

### **USERS:**
- ✅ **Credit card REQUIRED** on registration  
- ✅ Card stored in Stripe
- ✅ 14 days free trial
- ✅ $2.99/month after trial

---

## 🔑 Registration Payload

### Both Account Types Must Include:

```json
{
    // ... other registration fields ...
    
    // REQUIRED for ALL account types
    "payment_method_id": "pm_xxx",  // REQUIRED - No exceptions
    "billing_details": {             // REQUIRED - No exceptions
        "name": "...",
        "email": "...",
        "address": { ... }
    }
}
```

---

## ✅ Validation Rules

```php
'payment_method_id' => [
    'required',  // Required for ALL - no conditional
    'string',
    'starts_with:pm_',
],

'billing_details' => [
    'required',  // Required for ALL - no conditional
    'array',
],
```

**No exceptions** - Credit card is mandatory for both providers and users.

---

## 🎯 Key Points

1. ✅ **No registration without credit card** - Frontend must collect card before submission
2. ✅ **Both account types** - Provider and User both require card
3. ✅ **Stripe Elements** - Use Stripe Elements to securely collect card
4. ✅ **Payment method ID** - Only `payment_method_id` sent (not actual card number)
5. ✅ **Trial periods** - Card required even though trial is free

---

## 📝 Frontend Implementation

**Users CANNOT skip the credit card step during registration.**

The registration flow should be:
1. Fill registration form
2. **Enter credit card (required)** ← Cannot skip
3. Submit registration
4. Account created with subscription and trial

---

**Summary: Credit card is REQUIRED for both Providers and Users. No exceptions.**


