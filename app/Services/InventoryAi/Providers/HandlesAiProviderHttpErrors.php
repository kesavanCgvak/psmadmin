<?php

namespace App\Services\InventoryAi\Providers;

use App\Services\InventoryAi\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

trait HandlesAiProviderHttpErrors
{
    protected function logRequestStart(string $provider, string $model, ?int $inventoryMasterId = null): void
    {
        Log::info('Inventory AI enrichment request started.', array_filter([
            'provider' => $provider,
            'model' => $model,
            'inventory_master_id' => $inventoryMasterId,
        ]));
    }

    protected function logResponseReceived(string $provider, string $model, int $httpStatus): void
    {
        Log::info('Inventory AI enrichment response received.', [
            'provider' => $provider,
            'model' => $model,
            'http_status' => $httpStatus,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function classifyHttpFailure(
        Response $response,
        string $provider,
        string $endpoint,
        array $context = [],
    ): AiProviderException {
        $status = $response->status();
        $body = $response->json();
        $message = $this->extractErrorMessage($body) ?: $response->body();

        Log::error('Inventory AI enrichment API request failed.', array_merge([
            'provider' => $provider,
            'http_status' => $status,
            'endpoint' => $endpoint,
            'error_message' => $message,
        ], $context));

        if ($status === 401 || $status === 403) {
            return new AiProviderException(
                "AI provider authentication failed ({$provider}). Verify API key configuration. Detail: {$message}",
                AiProviderException::CATEGORY_INVALID_API_KEY,
                $provider,
                $status,
                false,
            );
        }

        if ($status === 429) {
            $category = $this->isQuotaError($body, $message)
                ? AiProviderException::CATEGORY_QUOTA_EXCEEDED
                : AiProviderException::CATEGORY_RATE_LIMIT;

            return new AiProviderException(
                "AI provider rate/quota limit exceeded ({$provider}). Detail: {$message}",
                $category,
                $provider,
                $status,
                $category === AiProviderException::CATEGORY_RATE_LIMIT,
            );
        }

        if ($status === 408 || $status === 504) {
            return new AiProviderException(
                "AI provider request timed out ({$provider}). Detail: {$message}",
                AiProviderException::CATEGORY_TIMEOUT,
                $provider,
                $status,
                true,
            );
        }

        if ($status >= 500) {
            return new AiProviderException(
                "AI provider unavailable ({$provider}). Detail: {$message}",
                AiProviderException::CATEGORY_UNAVAILABLE,
                $provider,
                $status,
                true,
            );
        }

        return new AiProviderException(
            "AI enrichment request failed with HTTP {$status} ({$provider}). Detail: {$message}",
            AiProviderException::CATEGORY_UNKNOWN,
            $provider,
            $status,
            false,
        );
    }

    protected function wrapConnectionException(ConnectionException $e, string $provider): AiProviderException
    {
        Log::error('Inventory AI enrichment network error.', [
            'provider' => $provider,
            'error' => $e->getMessage(),
        ]);

        return new AiProviderException(
            "AI provider network error ({$provider}): {$e->getMessage()}",
            AiProviderException::CATEGORY_TIMEOUT,
            $provider,
            null,
            true,
            $e,
        );
    }

    /**
     * @param  mixed  $body
     */
    private function extractErrorMessage(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        if (isset($body['error']['message']) && is_string($body['error']['message'])) {
            return $body['error']['message'];
        }

        if (isset($body['error']['status']) && is_string($body['error']['status'])) {
            return $body['error']['status'] . ': ' . ($body['error']['message'] ?? '');
        }

        return null;
    }

    /**
     * @param  mixed  $body
     */
    private function isQuotaError(mixed $body, string $message): bool
    {
        $haystack = strtolower($message);

        if (is_array($body)) {
            $code = strtolower((string) data_get($body, 'error.code', ''));
            $type = strtolower((string) data_get($body, 'error.type', ''));
            $status = strtolower((string) data_get($body, 'error.status', ''));

            if (str_contains($code, 'rate_limit') || str_contains($type, 'rate_limit')) {
                return false;
            }

            if (str_contains($code, 'quota') || str_contains($type, 'quota') || str_contains($status, 'quota')) {
                return true;
            }
        }

        if (str_contains($haystack, 'rate limit reached')) {
            return false;
        }

        return str_contains($haystack, 'quota')
            || str_contains($haystack, 'billing')
            || str_contains($haystack, 'insufficient_quota')
            || str_contains($haystack, 'resource_exhausted');
    }
}
