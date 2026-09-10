<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UserPresence
{
    public static function timeoutSeconds(): int
    {
        return max(0, (int) config('presence.online_status_timeout', 120));
    }

    public static function isOnline(?User $user): bool
    {
        if ($user === null || $user->last_seen_at === null) {
            return false;
        }

        return $user->last_seen_at->gte(now()->subSeconds(self::timeoutSeconds()));
    }

    public static function heartbeat(User $user): void
    {
        DB::table('users')->where('id', $user->getKey())->update([
            'last_seen_at' => now(),
        ]);
    }

    public static function clear(User $user): void
    {
        DB::table('users')->where('id', $user->getKey())->update([
            'last_seen_at' => null,
        ]);
    }

    /**
     * Company IDs that have at least one associated user currently online.
     *
     * @param  iterable<int|string|null>  $companyIds
     * @return array<int, int>
     */
    public static function onlineCompanyIds(iterable $companyIds): array
    {
        $ids = collect($companyIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('company_id', $ids)
            ->where('last_seen_at', '>=', now()->subSeconds(self::timeoutSeconds()))
            ->distinct()
            ->pluck('company_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
