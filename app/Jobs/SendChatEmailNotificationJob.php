<?php

namespace App\Jobs;

use App\Models\ChatNotificationLog;
use App\Services\ChatNotificationService;
use App\Support\ChatLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class SendChatEmailNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function backoff(): int
    {
        return app()->runningUnitTests() ? 0 : 60;
    }

    public function __construct(public int $notificationLogId) {}

    public function uniqueId(): string
    {
        return 'chat-email-log-'.$this->notificationLogId;
    }

    public function handle(ChatNotificationService $notifications): void
    {
        $notifications->deliverEmailNotification($this->notificationLogId);
    }

    public function failed(?Throwable $exception = null): void
    {
        $log = ChatNotificationLog::query()->find($this->notificationLogId);
        if ($log && ! $log->isTerminal()) {
            $this->mark($log, ChatNotificationLog::STATUS_FAILED, Str::limit((string) $exception?->getMessage(), 500));
        }

        ChatLog::error('Chat email notification failed permanently', [
            'notification_log_id' => $this->notificationLogId,
            'exception' => $exception?->getMessage(),
        ]);
    }

    private function mark(ChatNotificationLog $log, string $status, ?string $error = null): void
    {
        $log->forceFill([
            'status' => $status,
            'error_message' => $error,
            'processed_at' => now(),
        ])->save();
    }
}
