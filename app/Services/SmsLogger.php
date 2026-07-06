<?php

namespace App\Services;

use App\Models\SmsLog;
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
                'error_message' => $errorMessage,
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

        return ['raw' => is_scalar($response) ? $response : json_encode($response)];
    }
}
