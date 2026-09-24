<?php

namespace App\Support;

final class ChatChannels
{
    public static function conversation(int $conversationId): string
    {
        return 'chat.conversation.'.$conversationId;
    }

    public static function company(int $companyId): string
    {
        return 'chat.company.'.$companyId;
    }

    public static function online(): string
    {
        return 'chat.online';
    }
}
