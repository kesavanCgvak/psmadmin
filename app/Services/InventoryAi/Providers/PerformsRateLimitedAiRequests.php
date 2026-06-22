<?php

namespace App\Services\InventoryAi\Providers;

use App\Services\InventoryAi\AiRequestPacer;
use App\Services\InventoryAi\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

trait PerformsRateLimitedAiRequests
{
    /**
     * @param  callable(): Response  $sendRequest
     */
    protected function sendWithRateLimitHandling(
        callable $sendRequest,
        string $provider,
        string $endpoint,
        array $context = [],
    ): Response {
        $maxRetries = max(0, (int) config('ai.rate_limit.max_retries', 6));
        $initialBackoff = max(0.1, (float) config('ai.rate_limit.initial_backoff_seconds', 1));
        $maxBackoff = max($initialBackoff, (float) config('ai.rate_limit.max_backoff_seconds', 60));

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            AiRequestPacer::waitBeforeRequest();

            try {
                $response = $sendRequest();
            } catch (ConnectionException $e) {
                if ($attempt >= $maxRetries) {
                    throw $this->wrapConnectionException($e, $provider);
                }

                $this->sleepForBackoff($initialBackoff, $attempt, $maxBackoff);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() === 429) {
                $exception = $this->classifyHttpFailure($response, $provider, $endpoint, $context);

                if (!$exception->isRetryable() || $attempt >= $maxRetries) {
                    throw $exception;
                }

                $retryAfter = $this->resolveRetryAfterSeconds($response)
                    ?? $this->calculateBackoffSeconds($initialBackoff, $attempt, $maxBackoff);

                Log::warning('AI provider rate limit hit; backing off before retry.', [
                    'provider' => $provider,
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries,
                    'retry_after_seconds' => $retryAfter,
                    'endpoint' => $endpoint,
                ]);

                $this->sleepSeconds($retryAfter);

                continue;
            }

            throw $this->classifyHttpFailure($response, $provider, $endpoint, $context);
        }

        throw new AiProviderException(
            "AI provider rate limit retries exhausted ({$provider}).",
            AiProviderException::CATEGORY_RATE_LIMIT,
            $provider,
            429,
            false,
        );
    }

    protected function resolveRetryAfterSeconds(Response $response): ?float
    {
        $retryAfter = $response->header('Retry-After');
        if (is_string($retryAfter) && is_numeric($retryAfter)) {
            return max(0.0, (float) $retryAfter);
        }

        foreach (['x-ratelimit-reset-requests', 'x-ratelimit-reset-tokens'] as $header) {
            $value = $response->header($header);
            if (!is_string($value) || $value === '') {
                continue;
            }

            $seconds = $this->parseOpenAiResetDuration($value);
            if ($seconds !== null) {
                return $seconds;
            }
        }

        return null;
    }

    protected function parseOpenAiResetDuration(string $value): ?float
    {
        $value = strtolower(trim($value));

        if ($value === '' || $value === '0s') {
            return 0.0;
        }

        if (preg_match('/^(?:(\d+)m)?(?:(\d+(?:\.\d+)?)s)?$/', $value, $matches) === 1) {
            $minutes = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            $seconds = isset($matches[2]) && $matches[2] !== '' ? (float) $matches[2] : 0.0;

            if ($minutes > 0 || $seconds > 0) {
                return ($minutes * 60) + $seconds;
            }
        }

        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        return null;
    }

    protected function calculateBackoffSeconds(float $initialBackoff, int $attempt, float $maxBackoff): float
    {
        $exponential = $initialBackoff * (2 ** $attempt);
        $jitter = random_int(0, 1000) / 1000;

        return min($maxBackoff, $exponential * (1 + $jitter));
    }

    protected function sleepForBackoff(float $initialBackoff, int $attempt, float $maxBackoff): void
    {
        $this->sleepSeconds($this->calculateBackoffSeconds($initialBackoff, $attempt, $maxBackoff));
    }

    protected function sleepSeconds(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
