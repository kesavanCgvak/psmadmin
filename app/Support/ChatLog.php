<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class ChatLog
{
    public static function info(string $message, array $context = []): void
    {
        Log::channel('chat')->info($message, self::safeContext($context));
    }

    public static function warning(string $message, array $context = []): void
    {
        Log::channel('chat')->warning($message, self::safeContext($context));
    }

    public static function error(string $message, array $context = []): void
    {
        Log::channel('chat')->error($message, self::safeContext($context));
    }

    public static function broadcastFailed(string $event, ?Throwable $exception = null, array $context = []): void
    {
        self::error('Chat broadcast failed', array_merge($context, [
            'event' => $event,
            'exception' => $exception?->getMessage(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function safeContext(array $context): array
    {
        unset(
            $context['token'],
            $context['jwt'],
            $context['password'],
            $context['authorization'],
            $context['message'],
            $context['body'],
        );

        return $context;
    }
}
