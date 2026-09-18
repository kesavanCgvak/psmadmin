<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\ChatNotificationService;
use App\Support\ChatLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessChatMessageNotificationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $messageId,
        public int $conversationId,
        public int $senderUserId,
    ) {}

    public function uniqueId(): string
    {
        return 'chat-notify-message-'.$this->messageId;
    }

    public function handle(ChatNotificationService $notifications): void
    {
        $message = ChatMessage::query()->withTrashed()->find($this->messageId);
        $sender = User::query()->with('profile')->find($this->senderUserId);

        if (! $message || ! $sender) {
            return;
        }

        $conversation = $message->conversation()->with(['companyA', 'companyB'])->first();
        if (! $conversation || (int) $conversation->id !== $this->conversationId) {
            return;
        }

        $notifications->processMessage($message, $conversation, $sender);
    }

    public function failed(?Throwable $exception = null): void
    {
        ChatLog::error('Chat notification fan-out job failed permanently', [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'sender_user_id' => $this->senderUserId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
