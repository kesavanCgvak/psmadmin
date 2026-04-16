# Email Management System - Implementation Summary

## Overview

This document describes the Email Management System implemented to track all emails sent from the application. The implementation is **non-invasive** and does not modify existing email sending logic.

---

## 1. Current Email System Analysis

### Email Entry Points Identified

| Method | Location | Examples |
|--------|----------|----------|
| **EmailHelper::send()** | Multiple controllers | AuthController, ForgotPasswordController, RentalRequestController, UserOfferController, SupplyJobActionsController, JobNegotiationController, SupportRequestController, ContactSalesController, UserManagementController, CompanyUserController |
| **Mail::send()** | Direct usage | RentalRequestController (quoteRequest) |
| **Mail::to()->send(Mailable)** | Mailable classes | JobOfferNotificationMail, NewAdminUserCreated, SubscriptionCanceledNotification |
| **Notifications** | User model / ProductController | CustomResetPassword, CustomEmailVerification, NewProductCreated, ImportedProductsCreated |

All of these use Laravel's Mail facade internally, which dispatches `MessageSending` and `MessageSent` events. The event-based approach captures **all** emails automatically.

---

## 2. Database Table: `email_logs`

**Migration:** `database/migrations/2026_03_19_100000_create_email_logs_table.php`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| from_email | string (nullable) | Sender email |
| to_email | string | Recipient email |
| subject | string (nullable) | Email subject |
| email_type | string (nullable) | Inferred type (verification, forgot password, rental request, etc.) |
| status | enum (pending/sent/failed) | Delivery status |
| failure_reason | text (nullable) | Error message if failed |
| related_user_id | bigint (nullable, FK users) | User matched by to_email |
| reference_id | bigint (nullable) | e.g. rental request id (for future use) |
| mail_class | string (nullable) | Mailable class name if applicable |
| payload_snapshot | json (nullable) | Lightweight payload data |
| created_at, updated_at | timestamps | |

**Indexes:** status, email_type, created_at, to_email

---

## 3. Email Logging Implementation

### Event Listeners

- **LogEmailSending** (`app/Listeners/LogEmailSending.php`)
  - Listens to `Illuminate\Mail\Events\MessageSending`
  - Creates log entry with `status = pending` before send
  - Extracts from, to, subject from Symfony message
  - Infers email_type from subject
  - Resolves related_user_id by matching to_email to User or UserProfile
  - Adds custom header `X-Email-Log-Id` to correlate with MessageSent

- **LogEmailSent** (`app/Listeners/LogEmailSent.php`)
  - Listens to `Illuminate\Mail\Events\MessageSent`
  - Updates log to `status = sent` using the header

### Registration

**AppServiceProvider** (`app/Providers/AppServiceProvider.php`):
```php
Event::listen(MessageSending::class, LogEmailSending::class);
Event::listen(MessageSent::class, LogEmailSent::class);
```

### Failed Emails

When an email fails, `MessageSent` is never dispatched. Logs remain `pending`. A scheduled command marks stale pending logs as failed:

- **Command:** `php artisan email-logs:mark-stale-failed --minutes=60`
- **Schedule:** Runs hourly (in `routes/console.php`)

---

## 4. Admin Panel

### Routes

| Route | Method | Description |
|-------|--------|-------------|
| `admin.email-logs.index` | GET /admin/email-logs | List with filters |
| `admin.email-logs.show` | GET /admin/email-logs/{emailLog} | Detail view |

### List Page (`resources/views/admin/email-logs/index.blade.php`)

**Columns:** ID, From, To, Subject, Email Type, Status, Failure Reason, Sent At, Actions

**Filters:**
- Status (pending / sent / failed)
- Email type
- Date range (from / to)
- Search (from_email, to_email, subject)

### Detail Page (`resources/views/admin/email-logs/show.blade.php`)

- Full email details
- Payload snapshot (if any)
- Related user (with link to user profile)
- Reference ID (if set)

### Menu

Added "Email Logs" under System Settings in `config/adminlte.php` (after Email Templates).

---

## 5. Files Created / Modified

### Created

- `database/migrations/2026_03_19_100000_create_email_logs_table.php`
- `app/Models/EmailLog.php`
- `app/Listeners/LogEmailSending.php`
- `app/Listeners/LogEmailSent.php`
- `app/Console/Commands/MarkStaleEmailLogsAsFailed.php`
- `app/Http/Controllers/Admin/EmailLogController.php`
- `resources/views/admin/email-logs/index.blade.php`
- `resources/views/admin/email-logs/show.blade.php`

### Modified

- `app/Providers/AppServiceProvider.php` – Event listener registration
- `routes/web.php` – Email logs routes
- `routes/console.php` – Scheduled command for stale logs
- `config/adminlte.php` – Menu item

---

## 6. Safety & Backward Compatibility

- **No changes** to EmailHelper, Mailables, Notifications, or any email-sending code
- **No changes** to queue configuration
- Logging is **additive only** – if logging fails, the exception is caught and logged; email delivery continues
- Event listeners run in the same process; no extra queue jobs for logging (keeps it simple and avoids delays)

---

## 7. Performance

- Logging is synchronous but lightweight (single INSERT, then UPDATE)
- No heavy payload storage (payload_snapshot is null by default)
- Indexes on status, email_type, created_at, to_email for fast filtering
- Stale-failed command runs hourly, not on every request

---

## 8. Testing Scenarios

| Scenario | Expected |
|----------|----------|
| Verification email | Logged with email_type "verification", status "sent" |
| Forgot password email | Logged with email_type "forgot password", status "sent" |
| Rental request email | Logged with email_type "rental request", status "sent" |
| Failed email (e.g. invalid SMTP) | Log remains "pending"; after 60+ min, marked "failed" by scheduled command |
| Admin view logs | List page with filters works |
| Admin view detail | Detail page shows full info, related user |

---

## 9. Risks & Limitations

1. **Failed emails:** Immediate failure is not captured. Logs stay "pending" until the hourly command marks them "failed" after 60 minutes.
2. **Queued emails:** When emails are queued, `MessageSending`/`MessageSent` fire when the job runs (at send time), not when queued. Behavior is correct.
3. **related_user_id:** Resolved by matching to_email to User.email or UserProfile.email. May be null for external recipients.
4. **reference_id:** Not populated automatically. Would require passing context (e.g. rental_request_id) via a custom mechanism if needed later.

---

## 10. Optional Enhancements (Future)

- Populate `reference_id` via a custom header or context when sending (e.g. rental request id)
- Add `mail_class` extraction from Laravel Mailable metadata if available
- Store a minimal payload snapshot for debugging (e.g. template name for EmailHelper emails)
