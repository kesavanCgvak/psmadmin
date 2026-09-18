<?php

namespace App\Events;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
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
use Illuminate\Support\Str;
use Throwable;

class ChatMessageSent implements ShouldBroadcastNow, ShouldRescue
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
        return 'chat.message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $senderName = ChatIdentity::displayName($this->sender);
        $senderCompanyName = $this->senderCompanyName();
        $preview = Str::limit((string) $this->message->message, 80);

        return [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'id' => $this->message->id,
            'sender_user_id' => $this->sender->id,
            'sender_user_name' => $senderName,
            'sender_company_id' => $this->senderCompanyId,
            'sender_company_name' => $senderCompanyName,
            'message' => $this->message->message,
            'message_type' => $this->message->message_type,
            'created_at' => $this->message->created_at?->toIso8601String(),
            'rental_job_id' => $this->conversation->rental_job_id,
            'preview' => $preview,
            'notification' => [
                'title' => $senderName,
                'body' => $preview,
                'sender_name' => $senderName,
                'sender_company_name' => $senderCompanyName,
                'conversation_id' => $this->conversation->id,
                'message_id' => $this->message->id,
                'tag' => 'chat-'.$this->conversation->id,
            ],
        ];
    }

    private function senderCompanyName(): ?string
    {
        $this->conversation->loadMissing(['companyA', 'companyB']);

        if ((int) $this->conversation->company_a_id === $this->senderCompanyId) {
            return $this->conversation->companyA?->name;
        }

        if ((int) $this->conversation->company_b_id === $this->senderCompanyId) {
            return $this->conversation->companyB?->name;
        }

        return null;
    }

    public function failed(?Throwable $e = null): void
    {
        ChatLog::broadcastFailed('chat.message.sent', $e, [
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->message->id,
            'sender_user_id' => $this->sender->id,
            'sender_company_id' => $this->senderCompanyId,
        ]);
    }
}
