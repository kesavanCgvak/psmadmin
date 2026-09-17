<?php

namespace App\Policies;

use App\Models\ChatConversation;
use App\Models\User;

class ChatConversationPolicy
{
    public function view(User $user, ChatConversation $conversation): bool
    {
        return $conversation->hasCompanyParticipant($user);
    }

    public function sendMessage(User $user, ChatConversation $conversation): bool
    {
        return $conversation->hasCompanyParticipant($user);
    }

    public function markAsRead(User $user, ChatConversation $conversation): bool
    {
        return $conversation->hasCompanyParticipant($user);
    }
}
