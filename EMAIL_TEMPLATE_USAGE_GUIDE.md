# Email Template Usage Guide

## 🎯 Overview
To use email templates from the database (managed in admin panel), you need to use `EmailHelper::send()` instead of `Mail::send()`.

---

## ✅ Updated Code Examples

### Before (Using Blade Files Directly):
```php
Mail::send('emails.registrationSuccess', [
    'name' => $user->name,
    'email' => $user->email,
], function ($message) use ($email) {
    $message->to($email)
        ->subject('Welcome to ProSub Marketplace')
        ->from(config('mail.from.address'), config('mail.from.name'));
});
```

### After (Using Database Templates):
```php
use App\Helpers\EmailHelper;

EmailHelper::send('registrationSuccess', [
    'name' => $user->name,
    'email' => $user->email,
], function ($message) use ($email) {
    $message->to($email)
        // Subject is automatically set from template
        ->from(config('mail.from.address'), config('mail.from.name'));
});
```

---

## 📋 How It Works

1. **EmailHelper::send()** checks the database first for an active template
2. If found, uses the database template (subject and body)
3. If not found or disabled, falls back to blade file
4. Replaces variables in both subject and body

---

## 🔄 Migration Status

### ✅ Already Updated:
- `app/Http/Controllers/Admin/UserManagementController.php` - registrationSuccess, verificationEmail
- `app/Console/Commands/SendSupplyJobCompletionReminders.php` - jobCompletionReminder

### ⚠️ Still Need Update:
- `app/Http/Controllers/Api/UserOfferController.php` - rentalJobOffer
- `app/Http/Controllers/Api/CompanyUserController.php` - registrationSuccess, verificationEmail, newRegistration
- `app/Http/Controllers/Api/SupportRequestController.php` - support-request
- `app/Http/Controllers/Api/ContactSalesController.php` - contact-sales
- `app/Http/Controllers/Api/ForgotPasswordController.php` - forgotPassword
- `app/Http/Controllers/Api/SupplyJobActionsController.php` - supplyNewOffer
- `app/Console/Commands/SendRenterRatingReminders.php` - jobRatingReminder
- And other email sending locations...

---

## 🛠️ Quick Update Pattern

**Find:**
```php
Mail::send('emails.TEMPLATE_NAME', $data, function ($message) use (...) {
    $message->to(...)
        ->subject('...')
        ->from(...);
});
```

**Replace with:**
```php
\App\Helpers\EmailHelper::send('TEMPLATE_NAME', $data, function ($message) use (...) {
    $message->to(...)
        // Subject is set from template
        ->from(...);
});
```

---

## 📝 Notes

- **Subject**: Automatically set from template, but can be overridden in callback if needed
- **Variables**: Use `{{ $variable }}` format in templates (recommended)
- **Fallback**: If template not found in database, automatically uses blade file
- **Active Status**: Only active templates (`is_active = true`) are used from database

---

## 🚀 Benefits

1. ✅ Templates editable from admin panel
2. ✅ No code deployment needed for template changes
3. ✅ Automatic fallback to blade files
4. ✅ Consistent variable replacement
5. ✅ Subject line management

---

**Last Updated:** February 16, 2026
