<?php

namespace App\Events;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $recipientUserIds
     */
    public function __construct(
        public ChatMessage $message,
        public ChatConversation $conversation,
        public User $sender,
        public int $senderCompanyId,
        public int $recipientCompanyId,
        public array $recipientUserIds,
        public ?int $defaultContactUserId = null,
    ) {}

    /**
     * Channel names for Phase 2 Laravel Reverb broadcasting.
     * This event is not broadcast in Phase 1 (no ShouldBroadcast).
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.conversation.'.$this->conversation->id),
            new PrivateChannel('chat.company.'.$this->recipientCompanyId),
        ];

        foreach ($this->recipientUserIds as $userId) {
            $channels[] = new PrivateChannel('chat.user.'.$userId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    /**
     * Payload prepared for Phase 2 broadcasting and browser notifications.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'sender_user_id' => $this->sender->id,
            'sender_company_id' => $this->senderCompanyId,
            'recipient_company_id' => $this->recipientCompanyId,
            'recipient_user_ids' => $this->recipientUserIds,
            'default_contact_user_id' => $this->defaultContactUserId,
            'message' => [
                'id' => $this->message->id,
                'sender_user_id' => $this->message->sender_user_id,
                'sender_company_id' => $this->message->sender_company_id,
                'message' => $this->message->message,
                'message_type' => $this->message->message_type,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
        ];
    }
}
