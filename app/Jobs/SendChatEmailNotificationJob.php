<?php

namespace App\Jobs;

use App\Mail\ChatMessageReceivedMail;
use App\Models\ChatNotificationLog;
use App\Models\User;
use App\Support\ChatIdentity;
use App\Support\ChatLog;
use App\Support\ChatUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
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

    public function handle(): void
    {
        $log = ChatNotificationLog::query()->find($this->notificationLogId);
        if (! $log || $log->channel !== ChatNotificationLog::CHANNEL_EMAIL) {
            return;
        }

        if ($log->isTerminal()) {
            return;
        }

        $recipient = User::query()->with('profile')->find($log->user_id);
        $message = $log->message()->withTrashed()->first();
        $conversation = $log->conversation()->with(['companyA', 'companyB'])->first();
        $sender = $message?->sender()->with('profile')->first();

        if (! $recipient || ! $message || ! $conversation || ! $sender || $message->trashed()) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'missing_related_records');

            return;
        }

        if ((int) $recipient->id === (int) $sender->id) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'self');

            return;
        }

        if (! $conversation->hasCompanyParticipant($recipient)) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'not_participant');

            return;
        }

        $email = trim((string) ($recipient->preferred_email ?: $recipient->email ?: ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'no_email');

            return;
        }

        $senderCompanyName = $this->companyName($conversation, (int) $message->sender_company_id);
        $preview = Str::limit(trim((string) $message->message), 80);

        Mail::to($email)->send(new ChatMessageReceivedMail(
            recipientName: ChatIdentity::displayName($recipient),
            senderName: ChatIdentity::displayName($sender),
            senderCompanyName: $senderCompanyName ?: 'a company',
            preview: $preview,
            conversationUrl: ChatUrls::conversation((int) $conversation->id),
            conversationId: (int) $conversation->id,
        ));

        $this->mark($log, ChatNotificationLog::STATUS_SENT);
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

    private function companyName($conversation, int $companyId): ?string
    {
        if ((int) $conversation->company_a_id === $companyId) {
            return $conversation->companyA?->name;
        }

        if ((int) $conversation->company_b_id === $companyId) {
            return $conversation->companyB?->name;
        }

        return null;
    }
}
