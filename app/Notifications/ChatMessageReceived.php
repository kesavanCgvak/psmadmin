<?php

namespace App\Notifications;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ChatMessageReceived extends Notification
{
    use Queueable;

    public function __construct(
        public ChatMessage $message,
        public ChatConversation $conversation,
        public User $sender,
        public ?int $defaultContactUserId = null,
    ) {}

    /**
     * Phase 1 keeps the payload ready without delivering channels.
     * Phase 2 can add `broadcast` (browser push) and/or `database`.
     * Prefer notifying the recipient company's default contact first.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'sender_user_id' => $this->sender->id,
            'sender_company_id' => $this->message->sender_company_id,
            'sender_name' => $this->sender->profile?->full_name ?: $this->sender->getUsername(),
            'preview' => Str::limit($this->message->message, 120),
            'message_type' => $this->message->message_type,
            'default_contact_user_id' => $this->defaultContactUserId,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
