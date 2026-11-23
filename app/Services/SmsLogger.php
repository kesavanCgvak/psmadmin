<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Models\SupplyJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Centralized SMS audit logging.
 *
 * Every method is defensive: logging must NEVER break or block SMS sending,
 * so all persistence is wrapped in try/catch and failures are only logged.
 *
 * This service is provider-agnostic: any current or future SMS sender can
 * create a pending record and then mark it sent/failed, regardless of which
 * SmsProvider implementation performed the actual delivery.
 */
class SmsLogger
{
    /**
     * Create a "pending" SMS log record before a send attempt.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createPending(array $attributes): ?SmsLog
    {
        try {
            $attributes['status'] = SmsLog::STATUS_PENDING;
            $attributes['sent_by'] = $attributes['sent_by'] ?? SmsLog::SENT_BY_SYSTEM;

            return SmsLog::create($attributes);
        } catch (\Throwable $e) {
            Log::error('SmsLogger: failed to create pending SMS log.', [
                'error' => $e->getMessage(),
                'related_type' => $attributes['related_type'] ?? null,
                'related_id' => $attributes['related_id'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * Record one skipped attempt for a rental/supplier pair.
     *
     * The same reason is not inserted again. A skip for a start date that is
     * still more than 7 days away stays as history and does not block a later send.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordSkip(array $attributes, string $reason): ?SmsLog
    {
        try {
            $reason = $this->sanitizeError($reason) ?? $reason;

            return DB::transaction(function () use ($attributes, $reason) {
                $supplyJobId = $attributes['supply_job_id'] ?? null;
                if ($supplyJobId) {
                    $this->lockSupplyJob((int) $supplyJobId);
                }

                $existing = SmsLog::query()
                    ->where('related_type', $attributes['related_type'] ?? null)
                    ->where('related_id', $attributes['related_id'] ?? null)
                    ->where('company_id', $attributes['company_id'] ?? null)
                    ->where('status', SmsLog::STATUS_FAILED)
                    ->where('error_message', $reason)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $attributes['status'] = SmsLog::STATUS_FAILED;
                $attributes['error_message'] = $reason;
                $attributes['message'] = $attributes['message'] ?? $reason;
                $attributes['sent_by'] = $attributes['sent_by'] ?? SmsLog::SENT_BY_SYSTEM;
                $attributes['attempts'] = 0;
                unset($attributes['supply_job_id']);

                return SmsLog::create($attributes);
            });
        } catch (\Throwable $e) {
            Log::error('SmsLogger: failed to record skipped SMS.', [
                'error' => $this->sanitizeError($e->getMessage()),
                'related_type' => $attributes['related_type'] ?? null,
                'related_id' => $attributes['related_id'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * Keep a send log pending while the queue retries a temporary provider failure.
     */
    public function recordRetryableFailure(?SmsLog $log, ?string $errorMessage, mixed $providerResponse = null, ?int $attempts = null): void
    {
        if (! $log) {
            return;
        }

        try {
            $log->forceFill([
                'status' => SmsLog::STATUS_PENDING,
                'error_message' => $this->sanitizeError($errorMessage),
                'provider_response' => $this->normalizeResponse($providerResponse),
                'attempts' => $attempts ?? $log->attempts,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('SmsLogger: failed to record a retryable SMS failure.', [
                'sms_log_id' => $log->id,
                'error' => $this->sanitizeError($e->getMessage()),
            ]);
        }
    }

    /**
     * Remove account identifiers from provider errors before they are stored.
     */
    public function sanitizeError(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return $error;
        }

        $sanitized = preg_replace('#Accounts/AC[0-9a-fA-F]+#', 'Accounts/[redacted]', $error) ?? $error;
        $sanitized = preg_replace('#(auth[_ ]?token[=:]\s*)\S+#i', '$1[redacted]', $sanitized) ?? $sanitized;

        return $sanitized;
    }

    /**
     * Mark an SMS log as successfully sent (accepted by the provider).
     */
    public function markSent(?SmsLog $log, ?string $providerMessageId, mixed $providerResponse = null, ?int $attempts = null): void
    {
        if (!$log) {
            return;
        }

        try {
            $log->forceFill([
                'status' => SmsLog::STATUS_SENT,
                'provider_message_id' => $providerMessageId,
                'provider_response' => $this->normalizeResponse($providerResponse),
                'error_message' => null,
                'attempts' => $attempts ?? $log->attempts,
                'sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('SmsLogger: failed to mark SMS log as sent.', [
                'sms_log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark an SMS log as failed.
     */
    public function markFailed(?SmsLog $log, ?string $errorMessage, mixed $providerResponse = null, ?int $attempts = null): void
    {
        if (!$log) {
            return;
        }

        try {
            $log->forceFill([
                'status' => SmsLog::STATUS_FAILED,
                'error_message' => $this->sanitizeError($errorMessage),
                'provider_response' => $this->normalizeResponse($providerResponse),
                'attempts' => $attempts ?? $log->attempts,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('SmsLogger: failed to mark SMS log as failed.', [
                'sms_log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize any provider response shape into an array for JSON storage.
     */
    protected function normalizeResponse(mixed $response): ?array
    {
        if ($response === null) {
            return null;
        }

        if (is_array($response)) {
            return $response;
        }

        return ['raw' => is_scalar($response) ? (string) $response : json_encode($response)];
    }

    /**
     * Serialize access to one supply job across the web request and the scheduler.
     */
    public function lockSupplyJob(int $supplyJobId): void
    {
        $seconds = max(1, (int) config('services.sms.lock_wait_seconds', 5));

        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET innodb_lock_wait_timeout = '.$seconds);
        }

        SupplyJob::query()->whereKey($supplyJobId)->lockForUpdate()->first();
    }
}
