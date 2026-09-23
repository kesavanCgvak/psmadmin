<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class ChatLog
{
    /**
     * Broadcast failures recorded during the current request. Chat events broadcast
     * synchronously, so comparing this before and after a dispatch tells us whether
     * the Reverb HTTP call actually succeeded.
     */
    private static int $broadcastFailures = 0;

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
        self::$broadcastFailures++;

        self::error('Chat broadcast failed', array_merge($context, [
            'event' => $event,
            'exception' => $exception?->getMessage(),
        ]));
    }

    /**
     * The Reverb HTTP API rejected or never received the broadcast POST.
     */
    public static function reverbFailed(Throwable $exception, array $context = []): void
    {
        self::$broadcastFailures++;

        self::error('[CHAT-REVERB] Broadcast POST to Reverb failed', array_merge($context, [
            'exception' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'previous' => $exception->getPrevious()?->getMessage(),
            'reverb_target' => self::reverbTarget(),
        ]));
    }

    public static function broadcastFailureCount(): int
    {
        return self::$broadcastFailures;
    }

    /**
     * Where Laravel POSTs broadcasts, so a wrong host/port is visible. Never the key or secret.
     */
    public static function reverbTarget(): string
    {
        $options = config('broadcasting.connections.reverb.options', []);

        return sprintf(
            '%s://%s:%s',
            $options['scheme'] ?? 'http',
            $options['host'] ?? 'unknown',
            $options['port'] ?? 'unknown'
        );
    }

    /**
     * Wire-level channel names for diagnostics, e.g. presence-chat.conversation.7.
     *
     * @param  array<int, mixed>  $channels
     * @return list<string>
     */
    public static function channelNames(array $channels): array
    {
        return array_values(array_map(
            static fn ($channel): string => (string) $channel,
            $channels
        ));
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

        /*
         * Broadcaster exception messages can echo back the signed Pusher/Reverb URL,
         * so strip any credential-style query parameters before they reach the log.
         */
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = preg_replace(
                    '/\b(auth_key|auth_signature|secret|token|key)=[^&\s"\']+/i',
                    '$1=[redacted]',
                    $value
                );
            }
        }

        return $context;
    }
}
