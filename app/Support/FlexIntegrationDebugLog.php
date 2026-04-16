<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Structured Flex integration lines on the stack channel and storage/logs/flex-integration.log.
 */
final class FlexIntegrationDebugLog
{
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
        $lines[] = 'Action: ' . $action;
        $lines[] = 'Status: ' . $status;
        foreach ($details as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v);
            }
            $lines[] = $k . ': ' . $v;
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
}
