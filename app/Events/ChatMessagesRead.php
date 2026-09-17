<?php

namespace App\Events;

use App\Models\ChatConversation;
use App\Models\User;
use App\Support\ChatChannels;
use App\Support\ChatIdentity;
use App\Support\ChatLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ChatMessagesRead implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatConversation $conversation,
        public User $reader,
        public string $lastReadAt,
        public int $readerCompanyId,
        public int $otherCompanyId,
    ) {}

    /**
     * @return array<int, PresenceChannel|PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PresenceChannel(ChatChannels::conversation((int) $this->conversation->id)),
        ];

        if ($this->readerCompanyId > 0) {
            $channels[] = new PrivateChannel(ChatChannels::company($this->readerCompanyId));
        }

        if ($this->otherCompanyId > 0 && $this->otherCompanyId !== $this->readerCompanyId) {
            $channels[] = new PrivateChannel(ChatChannels::company($this->otherCompanyId));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.messages.read';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'reader_user_id' => $this->reader->id,
            'reader_user_name' => ChatIdentity::displayName($this->reader),
            'reader_company_id' => $this->readerCompanyId,
            'last_read_at' => $this->lastReadAt,
            'unread_count' => 0,
        ];
    }

    public function failed(?Throwable $e = null): void
    {
        ChatLog::broadcastFailed('chat.messages.read', $e, [
            'conversation_id' => $this->conversation->id,
            'reader_user_id' => $this->reader->id,
        ]);
    }
}
