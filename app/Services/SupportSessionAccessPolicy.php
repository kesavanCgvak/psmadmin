<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Decides which requests a support credential may perform.
 *
 * State-changing methods are rejected unless the path is an explicit
 * read-only exception. Safe methods are allowed unless the path is known
 * to reveal secrets or call an external system.
 */
class SupportSessionAccessPolicy
{
    /**
     * GET/HEAD routes that are not safe to call with a support credential.
     *
     * @var list<string>
     */
    private const SIDE_EFFECT_READS = [
        '#^api/provider/api-keys/\d+/reveal$#',
        '#^api/(cache-clear|config-clear|route-clear|view-clear|optimize-clear|optimize)$#',
        '#^api/flex/search$#',
        '#^api/flex/import/check$#',
        '#^api/rentman/equipment/import/check$#',
    ];

    /**
     * POST routes that query data and do not change user, company, or
     * marketplace records. Logout and token refresh are intentionally absent:
     * refresh could mint an unrestricted credential, and logout updates presence.
     *
     * @var list<string>
     */
    private const READ_ONLY_POSTS = [
        '#^api/companies/search$#',
        '#^api/support-sessions/current/end$#',
        '#^api/broadcasting/auth$#',
    ];

    public function allows(Request $request): bool
    {
        $path = trim($request->path(), '/');
        $method = strtoupper($request->getMethod());

        if ($method === 'OPTIONS') {
            return true;
        }

        if (in_array($method, ['GET', 'HEAD'], true)) {
            return ! $this->matches($path, self::SIDE_EFFECT_READS);
        }

        if ($method === 'POST' && $this->matches($path, self::READ_ONLY_POSTS)) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
