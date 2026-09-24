<?php

namespace App\Events;

use App\Models\ChatConversation;
use App\Models\User;
use App\Support\ChatChannels;
use App\Support\ChatIdentity;
use App\Support\ChatLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ChatUserTyping implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatConversation $conversation,
        public User $user,
        public bool $isTyping,
    ) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel(ChatChannels::conversation((int) $this->conversation->id)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.user.typing';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'user_name' => ChatIdentity::displayName($this->user),
            'is_typing' => $this->isTyping,
        ];
    }

    public function failed(?Throwable $e = null): void
    {
        ChatLog::broadcastFailed('chat.user.typing', $e, [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
        ]);
    }
}
