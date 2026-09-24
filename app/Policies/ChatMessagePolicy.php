<?php

namespace App\Policies;

use App\Models\ChatMessage;
use App\Models\User;

class ChatMessagePolicy
{
    public function delete(User $user, ChatMessage $message): bool
    {
        if ((int) $message->sender_user_id !== (int) $user->id) {
            return false;
        }

        $message->loadMissing('conversation');

        return $message->conversation?->hasCompanyParticipant($user) === true;
    }
}
