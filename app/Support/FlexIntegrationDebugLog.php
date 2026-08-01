<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Structured Flex integration lines on the stack channel and storage/logs/flex-integration.log.
 */
final class FlexIntegrationDebugLog
{
    /** @var array<string, int> step counters keyed by rentalRequestId:providerId */
    private static array $stepCounters = [];

    public static function resetStepCounter(int $rentalRequestId, ?int $providerId = null): void
    {
        self::$stepCounters[self::stepKey($rentalRequestId, $providerId)] = 0;
    }

    public static function nextStepNumber(int $rentalRequestId, ?int $providerId = null): int
    {
        $key = self::stepKey($rentalRequestId, $providerId);
        self::$stepCounters[$key] = (self::$stepCounters[$key] ?? 0) + 1;

        return self::$stepCounters[$key];
    }

    public static function debug(int $rentalRequestId, ?int $providerId, string $action, string $status, array $details = []): void
    {
        self::write('debug', $rentalRequestId, $providerId, $action, $status, $details);
    }

    public static function info(int $rentalRequestId, ?int $providerId, string $action, string $status, array $details = []): void
    {
        self::write('info', $rentalRequestId, $providerId, $action, $status, $details);
    }

    public static function warning(int $rentalRequestId, ?int $providerId, string $action, string $status, array $details = []): void
    {
        self::write('warning', $rentalRequestId, $providerId, $action, $status, $details);
    }

    public static function error(int $rentalRequestId, ?int $providerId, string $action, string $status, array $details = []): void
    {
        self::write('error', $rentalRequestId, $providerId, $action, $status, $details);
    }

    /**
     * Log a clear step marker (before/after an action) with optional next_step hint.
     */
    public static function step(
        int $rentalRequestId,
        ?int $providerId,
        string $action,
        string $status,
        array $details = [],
        ?string $nextStep = null,
    ): int {
        $step = self::nextStepNumber($rentalRequestId, $providerId);
        $payload = array_merge(['step' => $step], $details);
        if ($nextStep !== null && $nextStep !== '') {
            $payload['next_step'] = $nextStep;
        }

        $level = match (strtoupper($status)) {
            'FAILED', 'ERROR' => 'error',
            'SKIPPED', 'WARNING' => 'warning',
            'STARTED', 'PROCESSING', 'REQUEST' => 'debug',
            default => 'info',
        };

        self::write($level, $rentalRequestId, $providerId, $action, $status, $payload);

        return $step;
    }

    /**
     * Log a full Flex HTTP request/response for debugging.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function apiCall(
        int $rentalRequestId,
        ?int $providerId,
        string $action,
        string $method,
        string $url,
        mixed $requestPayload,
        ?int $httpStatus,
        mixed $responseBody,
        bool $success,
        ?string $nextStep = null,
        ?string $errorMessage = null,
        array $extra = [],
    ): int {
        $step = self::nextStepNumber($rentalRequestId, $providerId);
        $previewMax = (int) config('flex.log_response_preview_max', 8000);

        $details = array_merge([
            'step' => $step,
            'http_method' => strtoupper($method),
            'api_url' => $url,
            'request_payload' => self::encodeForLog($requestPayload, $previewMax),
            'http_status' => $httpStatus,
            'response_body' => self::encodeForLog($responseBody, $previewMax),
            'success' => $success,
        ], $extra);

        if ($nextStep !== null && $nextStep !== '') {
            $details['next_step'] = $nextStep;
        }
        if ($errorMessage !== null && $errorMessage !== '') {
            $details['error_message'] = $errorMessage;
        }

        self::write(
            $success ? 'info' : 'error',
            $rentalRequestId,
            $providerId,
            $action,
            $success ? 'API_SUCCESS' : 'API_FAILED',
            $details,
        );

        return $step;
    }

    public static function write(
        string $level,
        int $rentalRequestId,
        ?int $providerId,
        string $action,
        string $status,
        array $details = [],
    ): void {
        $lines = [
            '[Flex Integration]',
            'Rental Request ID: ' . $rentalRequestId,
        ];
        if ($providerId !== null) {
            $lines[] = 'Provider ID: ' . $providerId;
        }
        if (isset($details['step'])) {
            $lines[] = 'Step: ' . $details['step'];
        }
        $lines[] = 'Action: ' . $action;
        $lines[] = 'Status: ' . $status;

        if (!empty($details['http_method']) || !empty($details['api_url'])) {
            $lines[] = 'HTTP: ' . ($details['http_method'] ?? '') . ' ' . ($details['api_url'] ?? '');
        }
        if (array_key_exists('http_status', $details) && $details['http_status'] !== null) {
            $lines[] = 'HTTP Status: ' . $details['http_status'];
        }
        if (array_key_exists('request_payload', $details)) {
            $lines[] = 'Request Payload: ' . self::stringifyDetail($details['request_payload']);
        }
        if (array_key_exists('response_body', $details)) {
            $lines[] = 'Response: ' . self::stringifyDetail($details['response_body']);
        }
        if (!empty($details['next_step'])) {
            $lines[] = 'Next Step: ' . $details['next_step'];
        }

        foreach ($details as $k => $v) {
            if (in_array($k, ['step', 'http_method', 'api_url', 'http_status', 'request_payload', 'response_body', 'next_step'], true)) {
                continue;
            }
            $lines[] = $k . ': ' . self::stringifyDetail($v);
        }

        $message = implode("\n", $lines);
        $context = array_merge([
            'rental_request_id' => $rentalRequestId,
            'provider_id' => $providerId,
            'flex_action' => $action,
            'flex_status' => $status,
        ], $details);

        foreach (['stack', 'flex'] as $channel) {
            try {
                Log::channel($channel)->log($level, $message, $context);
            } catch (\Throwable) {
                // Avoid breaking rental flow if a log channel is misconfigured
            }
        }
    }

    private static function stepKey(int $rentalRequestId, ?int $providerId): string
    {
        return $rentalRequestId . ':' . ($providerId ?? '0');
    }

    private static function stringifyDetail(mixed $v): string
    {
        if (is_array($v) || is_object($v)) {
            $encoded = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $encoded !== false ? $encoded : '(json_encode failed)';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }

        return (string) $v;
    }

    private static function encodeForLog(mixed $data, int $maxChars): mixed
    {
        if ($data === null) {
            return null;
        }

        if (is_string($data)) {
            if (strlen($data) <= $maxChars) {
                return $data;
            }

            return substr($data, 0, $maxChars) . '… [truncated, total ' . strlen($data) . ' bytes]';
        }

        if (is_array($data) || is_object($data)) {
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return ['_error' => 'json_encode failed'];
            }
            if (strlen($encoded) <= $maxChars) {
                return $data;
            }

            return substr($encoded, 0, $maxChars) . '… [truncated, total ' . strlen($encoded) . ' chars]';
        }

        return $data;
    }
}
