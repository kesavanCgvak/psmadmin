<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAuthEvent;
use Illuminate\Http\Request;

class AuthEventLogger
{
    public static function logLogin(?User $user, Request $request, string $channel): void
    {
        if (! $user) {
            return;
        }

        self::insert($user->id, UserAuthEvent::EVENT_LOGIN, $channel, $request, null);
    }

    public static function logLogout(?User $user, Request $request, string $channel): void
    {
        if (! $user) {
            return;
        }

        self::insert($user->id, UserAuthEvent::EVENT_LOGOUT, $channel, $request, null);
    }

    /**
     * @param  string  $channel  web|api
     */
    public static function logFailedLogin(Request $request, string $channel, ?string $identifier = null): void
    {
        UserAuthEvent::create([
            'user_id' => null,
            'event_type' => UserAuthEvent::EVENT_FAILED_LOGIN,
            'channel' => $channel,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'identifier' => $identifier ?? $request->input('email') ?? $request->input('username'),
        ]);
    }

    private static function insert(int $userId, string $eventType, string $channel, Request $request, ?string $identifier): void
    {
        UserAuthEvent::create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'channel' => $channel,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'identifier' => $identifier,
        ]);
    }
}
