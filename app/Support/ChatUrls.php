<?php

namespace App\Support;

final class ChatUrls
{
    public static function conversation(int $conversationId): string
    {
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $path = str_replace(
            '{id}',
            (string) $conversationId,
            (string) config('chat.conversation_path', '#/chat?conversation={id}')
        );

        if ($path === '') {
            $path = '#/chat?conversation='.$conversationId;
        }

        return $frontend.'/'.ltrim($path, '/');
    }
}
