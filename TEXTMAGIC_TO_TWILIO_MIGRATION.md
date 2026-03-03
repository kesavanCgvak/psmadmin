# TextMagic to Twilio SMS Migration Guide

This document provides a structured, implementation-ready approach for migrating from TextMagic to Twilio for transactional SMS notifications in the PSM Admin Panel.

---

## Current SMS Workflow Summary

| Component | Location | Purpose |
|-----------|----------|---------|
| **Trigger** | `RentalRequestController` (line 268) | Calls `SupplierSmsNotifier::notifyIfNeeded()` after rental request creation |
| **Condition** | `SupplierSmsNotifier::shouldSendSms()` | Job begin date (from_date) < 8 days from today, not in the past |
| **Dispatcher** | `SupplierSmsNotifier::notifyIfNeeded()` | Validates supplier mobile, dispatches `SendSupplierSmsJob` |
| **Queue Job** | `SendSupplierSmsJob` | Queued job (3 retries, 60s delay between attempts) |
| **SMS Provider** | `TextMagicService` | Sends SMS via TextMagic REST API |
| **Config** | `config/services.php` | `TEXTMAGIC_USERNAME`, `TEXTMAGIC_API_KEY` |
| **Env** | `.env.example` | `TEXTMAGIC_USERNAME`, `TEXTMAGIC_API_KEY` |

**Important:** No Composer package for TextMagic—custom HTTP implementation. No cron/scheduler involvement—SMS is triggered on-demand via queue.

---

## 1️⃣ Migration Strategy

### Steps to Safely Remove TextMagic

1. **Create Twilio service and interface** (new code, no removal yet)
2. **Introduce provider abstraction** (interface-based design)
3. **Wire Twilio as default provider** in service container
4. **Update `SendSupplierSmsJob`** to use the new abstraction
5. **Update `SupplierSmsNotifier`** to use shared validation (no TextMagic-specific calls)
6. **Remove TextMagic** from config, env, and delete `TextMagicService.php`
7. **Deploy and verify** in staging, then production

### Areas to Check

| Area | Files | Action |
|------|-------|--------|
| **Services** | `app/Services/TextMagicService.php` | Replace with `TwilioSmsService` + interface |
| **Services** | `app/Services/SupplierSmsNotifier.php` | Remove `TextMagicService` dependency; use shared validation |
| **Jobs** | `app/Jobs/SendSupplierSmsJob.php` | Inject `SmsProviderInterface` instead of `TextMagicService` |
| **Controllers** | `app/Http/Controllers/Api/RentalRequestController.php` | No change (uses `SupplierSmsNotifier` only) |
| **Config** | `config/services.php` | Replace `textmagic` with `twilio` |
| **Env** | `.env`, `.env.example` | Replace `TEXTMAGIC_*` with `TWILIO_*` |
| **Cron/Queues** | `routes/console.php`, queue workers | No change (SMS uses queue, not scheduler) |
| **Composer** | `composer.json` | Add `twilio/sdk`; no TextMagic package to remove |

### How to Avoid Breaking Existing SMS Functionality

- Use **interface-based design** so the job depends on `SmsProviderInterface`, not a concrete provider
- Implement **feature flag** (optional): `SMS_PROVIDER=twilio` in `.env` to switch providers without code deploy
- **Parallel run** (optional): Log to both providers in a transition period; only Twilio sends
- **Retry logic** remains in `SendSupplierSmsJob` (3 tries, 60s backoff)
- **Deduplication** via cache key `supplier_sms_sent:{supply_job_id}` stays unchanged

---

## 2️⃣ Twilio Integration Plan

### Required Twilio Setup Steps

1. **Create Twilio account**: [twilio.com/try-twilio](https://www.twilio.com/try-twilio)
2. **Get credentials** from [Twilio Console](https://console.twilio.com):
   - Account SID
   - Auth Token
3. **Buy an SMS-enabled phone number**: Phone Numbers → Manage → Buy a number (with SMS capability)
4. **Trial accounts**: Can send only to verified numbers; upgrade for production

### Backend Implementation Approach

- **Service layer**: `TwilioSmsService` implements `SmsProviderInterface`
- **Interface**: `SmsProviderInterface` with `sendSms(string $to, string $text): array` and `isValidMobile(?string $mobile): bool`
- **Binding**: Laravel service container binds `SmsProviderInterface` to `TwilioSmsService`

### Recommended Architecture (Interface-Based Provider Design)

```
app/
├── Contracts/
│   └── SmsProviderInterface.php      # sendSms(), isValidMobile(), isConfigured()
├── Services/
│   ├── TwilioSmsService.php           # Implements SmsProviderInterface
│   └── SupplierSmsNotifier.php        # Uses SmsProviderInterface for validation only
└── Jobs/
    └── SendSupplierSmsJob.php         # Injects SmsProviderInterface
```

### Authentication & Secure Storage

- **Credentials**: Account SID, Auth Token, and "From" phone number
- **Storage**: `.env` only; never commit to version control
- **Config**: `config/services.php` reads from env and exposes via `config('services.twilio.*')`

```env
# Twilio SMS (optional - SMS notifications disabled when not set)
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_SMS_FROM=
```

**Config** (`config/services.php`):

```php
'twilio' => [
    'sid' => env('TWILIO_ACCOUNT_SID'),
    'token' => env('TWILIO_AUTH_TOKEN'),
    'from' => env('TWILIO_SMS_FROM'),
],
```

**Service container binding** (in `AppServiceProvider::register()`):

```php
$this->app->bind(
    \App\Contracts\SmsProviderInterface::class,
    \App\Services\TwilioSmsService::class
);
```

---

## 3️⃣ Code-Level Suggestions

### Suggested Structure for Twilio Service Class

```php
<?php

namespace App\Services;

use App\Contracts\SmsProviderInterface;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class TwilioSmsService implements SmsProviderInterface
{
    protected ?Client $client = null;

    public function __construct()
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        if ($sid && $token) {
            $this->client = new Client($sid, $token);
        }
    }

    public function isConfigured(): bool
    {
        return $this->client !== null
            && !empty(config('services.twilio.from'));
    }

    public function isValidMobile(?string $mobile): bool
    {
        if (empty($mobile) || !is_string($mobile)) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $mobile);
        return strlen($digits) >= 10;
    }

    /**
     * Format phone to E.164 (Twilio expects +1234567890).
     */
    public function formatPhoneForApi(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile);
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }
        return '+' . $digits;
    }

    public function sendSms(string $to, string $text): array
    {
        if (!$this->isConfigured()) {
            Log::warning('Twilio SMS skipped: missing credentials.');
            return ['success' => false, 'error' => 'Twilio not configured'];
        }

        if (!$this->isValidMobile($to)) {
            Log::warning('Twilio SMS skipped: invalid mobile number.', [
                'to' => substr($to, 0, 4) . '***',
            ]);
            return ['success' => false, 'error' => 'Invalid mobile number'];
        }

        $toFormatted = $this->formatPhoneForApi($to);
        $from = config('services.twilio.from');

        try {
            $message = $this->client->messages->create($toFormatted, [
                'body' => $text,
                'from' => $from,
            ]);

            Log::info('Twilio SMS sent successfully.', [
                'message_sid' => $message->sid,
                'phones_preview' => substr($toFormatted, 0, 5) . '***',
            ]);

            return ['success' => true, 'message_id' => $message->sid];
        } catch (\Twilio\Exceptions\RestException $e) {
            Log::error('Twilio SMS API failed.', [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'phones_preview' => substr($toFormatted, 0, 5) . '***',
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('Twilio SMS exception.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
```

### Interface Contract

```php
<?php

namespace App\Contracts;

interface SmsProviderInterface
{
    public function isConfigured(): bool;

    public function isValidMobile(?string $mobile): bool;

    /**
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function sendSms(string $to, string $text): array;
}
```

### Error Handling and Logging Best Practices

- **Log levels**: `warning` for skipped sends (no config, invalid mobile), `error` for API failures
- **PII**: Never log full phone numbers; use `substr($phone, 0, 5) . '***'`
- **Twilio exceptions**: Catch `\Twilio\Exceptions\RestException` for API errors (rate limits, invalid number, etc.)
- **Return shape**: Always `['success' => bool, 'message_id' => ?string, 'error' => ?string]` for consistency

### Handling Delivery Failures

- Twilio returns immediately on `create()`; delivery status is async
- For transactional SMS, immediate API success is usually sufficient
- Optional: Use [Twilio Status Callbacks](https://www.twilio.com/docs/sms/api/message-resource#message-status-values) to log delivery/failure to your system

### Retry Mechanism

- **Current**: `SendSupplierSmsJob` has `tries(): 3` and `release(60)` on failure
- **Recommendation**: Keep as-is; Twilio transient errors (rate limit, timeout) benefit from retries
- **Optional**: Add `backoff()` for exponential backoff (e.g. 60, 120, 300 seconds)

---

## 4️⃣ Improvements Over Current Setup

### Benefits of Twilio vs TextMagic

| Aspect | TextMagic | Twilio |
|--------|-----------|--------|
| **Global reach** | Good | Excellent (250+ countries) |
| **Documentation** | Good | Excellent, extensive |
| **SDK** | Custom HTTP | Official `twilio/sdk` with type hints |
| **Ecosystem** | SMS-focused | SMS, Voice, WhatsApp, Video |
| **Status callbacks** | Limited | Full webhook support |
| **Enterprise adoption** | SMB | Enterprise-grade |
| **Developer experience** | Manual REST | SDK, helpers, tooling |

### Rate Limits

- **Twilio**: Varies by account; typically 1 msg/sec for new accounts, higher for verified
- **TextMagic**: Similar per-account limits
- **Recommendation**: For transactional SMS (one per request), limits are rarely hit; if scaling, consider Twilio's higher throughput options

### Cost Considerations

- **Twilio**: Pay-as-you-go; US SMS ~$0.0079/msg (varies by country)
- **TextMagic**: ~$0.049/SMS
- **For low-volume transactional SMS**: Both are affordable; Twilio often cheaper at scale
- **Action**: Review [Twilio pricing](https://www.twilio.com/sms/pricing) for your target countries

### Scalability Recommendations

- Keep **queue-based** sending (already in place)
- Consider **Redis queue** instead of database for higher throughput
- Use **Twilio Messaging Service** for multiple numbers and load balancing
- Monitor **failed_jobs** table and set up alerts

---

## 5️⃣ Testing & Deployment Plan

### How to Test in Development

1. **Install Twilio SDK**: `composer require twilio/sdk`
2. **Add `.env` vars**: `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_SMS_FROM`
3. **Use Twilio trial**: Verify your dev phone number in Twilio Console
4. **Create a test rental request** with Job Out date < 8 days from today
5. **Run queue worker**: `php artisan queue:work` (or use `queue:listen` from `composer dev`)
6. **Verify**: SMS received on verified number; check `storage/logs/laravel.log` for success/failure

### Sandbox / Testing Numbers

- **Twilio Trial**: Send only to verified numbers; good for dev
- **Twilio Virtual Phone**: In-browser testing without a real device
- **Production**: Purchase a number and remove trial restrictions

### Production Rollout Strategy

1. **Staging**: Deploy Twilio integration; keep TextMagic env vars as fallback (optional)
2. **Feature flag** (optional): `SMS_PROVIDER=twilio` to switch without redeploy
3. **Production deploy**: Add Twilio env vars; remove TextMagic env vars
4. **Monitor**: Check `failed_jobs`, logs, and a few real requests
5. **Cleanup**: Remove TextMagic config and `TextMagicService.php` in a follow-up PR

---

## Implementation Checklist

- [ ] Create `App\Contracts\SmsProviderInterface`
- [ ] Create `App\Services\TwilioSmsService` implementing the interface
- [ ] Add `twilio` config to `config/services.php`
- [ ] Register binding in `AppServiceProvider` or `SmsServiceProvider`
- [ ] Update `SendSupplierSmsJob` to inject `SmsProviderInterface`
- [ ] Update `SupplierSmsNotifier` to use interface for `isValidMobile()` (or extract to shared helper)
- [ ] Add `TWILIO_*` to `.env.example`; remove `TEXTMAGIC_*`
- [ ] Run `composer require twilio/sdk`
- [ ] Delete `TextMagicService.php`
- [ ] Remove `textmagic` from `config/services.php`
- [ ] Test in dev with Twilio trial
- [ ] Deploy to staging, verify SMS delivery
- [ ] Deploy to production
- [ ] Remove TextMagic credentials from production `.env`

---

## Important Notes

- **No OTP verification**: This migration is for transactional notifications only; OTP is out of scope unless you add it later.
- **Clean migration**: No legacy TextMagic dependency remains after completion.
- **Backward compatibility**: The `SupplierSmsNotifier` and `SendSupplierSmsJob` flow stays the same; only the underlying SMS provider changes.
