<?php

namespace App\Services\InventoryAi;

use Illuminate\Support\Facades\Cache;

/**
 * Enforces a global minimum interval between AI API requests (RPM pacing).
 *
 * @see https://developers.openai.com/api/docs/guides/rate-limits
 */
final class AiRequestPacer
{
    private const CACHE_KEY = 'inventory_ai:pacer:last_request';

    private const LOCK_KEY = 'inventory_ai:pacer';

    public static function waitBeforeRequest(): void
    {
        $rpm = max(1, (int) config('ai.rate_limit.requests_per_minute', 3));
        $minIntervalSeconds = 60.0 / $rpm;

        $lock = Cache::lock(self::LOCK_KEY, 120);

        $lock->block(120, function () use ($minIntervalSeconds): void {
            $lastRequestAt = (float) Cache::get(self::CACHE_KEY, 0.0);
            $now = microtime(true);
            $elapsed = $now - $lastRequestAt;

            if ($lastRequestAt > 0 && $elapsed < $minIntervalSeconds) {
                $sleepSeconds = $minIntervalSeconds - $elapsed;
                usleep((int) round($sleepSeconds * 1_000_000));
            }

            Cache::put(self::CACHE_KEY, microtime(true), now()->addMinutes(10));
        });
    }

    public static function secondsBetweenRequests(): float
    {
        $rpm = max(1, (int) config('ai.rate_limit.requests_per_minute', 3));

        return 60.0 / $rpm;
    }
}
