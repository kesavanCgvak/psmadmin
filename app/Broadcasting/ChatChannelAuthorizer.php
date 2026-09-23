<?php

namespace App\Broadcasting;

use App\Models\ChatConversation;
use App\Models\User;
use App\Support\ChatIdentity;
use App\Support\ChatLog;
use App\Support\UserPresence;

class ChatChannelAuthorizer
{
    /**
     * Presence channel for a company-to-company conversation.
     *
     * @return array{id: int, user_id: int, user_name: string, company_id: int|null}|false
     */
    public function conversation(?User $user, int $conversationId): array|false
    {
        if (! $this->isChatUser($user)) {
            $this->deny('chat.conversation', $user, ['conversation_id' => $conversationId]);

            return false;
        }

        $conversation = ChatConversation::query()->find($conversationId);
        if (! $conversation || ! $conversation->hasCompanyParticipant($user)) {
            $this->deny('chat.conversation', $user, ['conversation_id' => $conversationId]);

            return false;
        }

        UserPresence::heartbeat($user);
        $this->grant('chat.conversation.'.$conversationId, $user, ['conversation_id' => $conversationId]);

        return ChatIdentity::presencePayload($user);
    }

    /**
     * Private inbox channel for a single company. Users cannot spoof another company.
     */
    public function company(?User $user, int $companyId): bool
    {
        if (! $this->isChatUser($user) || (int) $user->company_id !== $companyId) {
            $this->deny('chat.company', $user, ['requested_company_id' => $companyId]);

            return false;
        }

        $this->grant('chat.company.'.$companyId, $user);

        return true;
    }

    /**
     * Global presence of authenticated PSM users with a company.
     *
     * @return array{id: int, user_id: int, user_name: string, company_id: int|null}|false
     */
    public function online(?User $user): array|false
    {
        if (! $this->isChatUser($user)) {
            $this->deny('chat.online', $user);

            return false;
        }

        UserPresence::heartbeat($user);
        $this->grant('chat.online', $user);

        return ChatIdentity::presencePayload($user);
    }

    private function isChatUser(?User $user): bool
    {
        return $user !== null && (int) ($user->company_id ?? 0) > 0;
    }

    /**
     * Subscription granted. Without this, a client that never subscribes looks
     * identical to one that subscribed successfully.
     *
     * @param  array<string, mixed>  $context
     */
    private function grant(string $channel, ?User $user, array $context = []): void
    {
        ChatLog::info('[CHAT-REVERB] Channel subscription authorized', array_merge($context, [
            'channel' => $channel,
            'user_id' => $user?->id,
            'company_id' => $user?->company_id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function deny(string $channel, ?User $user, array $context = []): void
    {
        ChatLog::warning('Unauthorized chat channel subscription', array_merge($context, [
            'channel' => $channel,
            'user_id' => $user?->id,
            'company_id' => $user?->company_id,
        ]));
    }
}
