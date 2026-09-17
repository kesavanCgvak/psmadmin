<?php

namespace App\Events;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Support\ChatChannels;
use App\Support\ChatLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ChatMessageDeleted implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message,
        public ChatConversation $conversation,
        public User $deletedBy,
        public int $senderCompanyId,
        public int $recipientCompanyId,
    ) {}

    /**
     * @return array<int, PresenceChannel|PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PresenceChannel(ChatChannels::conversation((int) $this->conversation->id)),
        ];

        if ($this->recipientCompanyId > 0) {
            $channels[] = new PrivateChannel(ChatChannels::company($this->recipientCompanyId));
        }

        if ($this->senderCompanyId > 0 && $this->senderCompanyId !== $this->recipientCompanyId) {
            $channels[] = new PrivateChannel(ChatChannels::company($this->senderCompanyId));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.message.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'id' => $this->message->id,
            'deleted_by_user_id' => $this->deletedBy->id,
            'deleted_at' => $this->message->deleted_at?->toIso8601String(),
            'is_deleted' => true,
        ];
    }

    public function failed(?Throwable $e = null): void
    {
        ChatLog::broadcastFailed('chat.message.deleted', $e, [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'deleted_by_user_id' => $this->deletedBy->id,
        ]);
    }
}
