<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Structured HireTrack integration lines on the stack channel and storage/logs/hiretrack-integration.log.
 */
final class HireTrackIntegrationDebugLog
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
            'STARTED', 'PROCESSING' => 'debug',
            default => 'info',
        };

        self::write($level, $rentalRequestId, $providerId, $action, $status, $payload);

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
            '[HireTrack Integration]',
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

        if (!empty($details['next_step'])) {
            $lines[] = 'Next Step: ' . $details['next_step'];
        }

        foreach ($details as $k => $v) {
            if (in_array($k, ['step', 'next_step'], true)) {
                continue;
            }
            $lines[] = $k . ': ' . self::stringifyDetail($v);
        }

        $message = implode("\n", $lines);
        $context = array_merge([
            'rental_request_id' => $rentalRequestId,
            'provider_id' => $providerId,
            'hiretrack_action' => $action,
            'hiretrack_status' => $status,
        ], $details);

        foreach (['stack', 'hiretrack'] as $channel) {
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
}
