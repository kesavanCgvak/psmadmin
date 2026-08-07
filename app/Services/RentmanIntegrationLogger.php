<?php

namespace App\Services;

use App\Models\RentmanIntegrationLog;
use App\Support\RentmanIntegrationDebugLog;

/**
 * Persists rentman_integration_logs rows and writes structured debug lines to the stack + rentman log files.
 */
class RentmanIntegrationLogger
{
    public function __construct(
        protected int $rentalRequestId,
        protected int $providerId,
    ) {}

    public function log(
        string $action,
        string $status,
        ?string $requestUrl = null,
        mixed $requestPayload = null,
        mixed $responsePayload = null,
        ?string $errorMessage = null,
        ?string $rentmanProjectRequestId = null,
        ?string $rentmanEquipmentId = null,
    ): void {
        $normalizedRequest = $this->normalizePayload($requestPayload);
        $normalizedResponse = $this->normalizePayload($responsePayload);

        RentmanIntegrationLog::query()->create([
            'rental_request_id' => $this->rentalRequestId,
            'provider_id' => $this->providerId,
            'action' => $action,
            'status' => $status,
            'request_url' => $requestUrl,
            'request_payload' => $normalizedRequest,
            'response_payload' => $normalizedResponse,
            'error_message' => $errorMessage,
            'rentman_project_request_id' => $rentmanProjectRequestId,
            'rentman_equipment_id' => $rentmanEquipmentId,
        ]);

        $previewLimit = (int) config('rentman.log_response_preview_max', 8000);

        $details = array_filter([
            'request_url' => $requestUrl,
            'error_message' => $errorMessage,
            'rentman_project_request_id' => $rentmanProjectRequestId,
            'rentman_equipment_id' => $rentmanEquipmentId,
        ], fn ($v) => $v !== null && $v !== '');

        if ($normalizedRequest !== null) {
            $details['request_payload_json'] = self::truncateJson($normalizedRequest, $previewLimit);
        }
        if ($normalizedResponse !== null) {
            $details['response_payload_json'] = self::truncateJson($normalizedResponse, $previewLimit);
        }

        $level = match ($status) {
            RentmanIntegrationLog::STATUS_FAILED => 'error',
            RentmanIntegrationLog::STATUS_SKIPPED => 'warning',
            RentmanIntegrationLog::STATUS_PROCESSING => 'debug',
            default => 'info',
        };

        RentmanIntegrationDebugLog::write(
            $level,
            $this->rentalRequestId,
            $this->providerId,
            $action,
            $status,
            $details,
        );
    }

    protected static function truncateJson(?array $data, int $maxChars): string
    {
        if ($data === null) {
            return '';
        }

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return '(json_encode failed)';
        }

        if (strlen($encoded) <= $maxChars) {
            return $encoded;
        }

        return substr($encoded, 0, $maxChars) . '… [truncated, total ' . strlen($encoded) . ' chars]';
    }

    protected function normalizePayload(mixed $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : ['raw' => self::truncateString($payload, 10000)];
        }

        return ['value' => $payload];
    }

    protected static function truncateString(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max) . '… [truncated]';
    }
}
