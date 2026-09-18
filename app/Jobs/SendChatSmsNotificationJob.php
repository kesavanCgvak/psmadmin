<?php

namespace App\Jobs;

use App\Contracts\SmsProvider;
use App\Models\ChatNotificationLog;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\SmsLogger;
use App\Support\ChatIdentity;
use App\Support\ChatLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendChatSmsNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function backoff(): int
    {
        return app()->runningUnitTests() ? 0 : 60;
    }

    public ?int $smsLogId = null;

    public function __construct(public int $notificationLogId) {}

    public function uniqueId(): string
    {
        return 'chat-sms-log-'.$this->notificationLogId;
    }

    public function handle(SmsProvider $smsProvider, SmsLogger $smsLogger): void
    {
        $log = ChatNotificationLog::query()->find($this->notificationLogId);
        if (! $log || $log->channel !== ChatNotificationLog::CHANNEL_SMS) {
            return;
        }

        if ($log->isTerminal()) {
            return;
        }

        $recipient = User::query()->with(['profile', 'company', 'chatUserSetting'])->find($log->user_id);
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

        $settings = $recipient->chatUserSetting;
        if (! $settings?->sms_notifications_enabled || $settings->sms_consented_at === null) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'sms_disabled');

            return;
        }

        $mobile = trim((string) ($recipient->profile?->mobile ?? ''));
        if ($mobile === '' || ! $smsProvider->isValidMobile($mobile)) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'no_mobile');

            return;
        }

        if (! $smsProvider->isConfigured()) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'sms_not_configured');

            return;
        }

        $senderCompanyName = $this->companyName($conversation, (int) $message->sender_company_id) ?: 'a company';
        $body = sprintf(
            'PSM: You have a new message from %s at %s. Open Pro Subrental Marketplace to reply.',
            ChatIdentity::displayName($sender),
            $senderCompanyName
        );

        $smsLog = $this->smsLogId ? SmsLog::query()->find($this->smsLogId) : null;
        if (! $smsLog) {
            $smsLog = $smsLogger->createPending([
                'provider' => config('services.sms.driver'),
                'message' => $body,
                'recipient_name' => ChatIdentity::displayName($recipient),
                'phone_number' => $mobile,
                'company_id' => $recipient->company_id,
                'company_name' => $recipient->company?->name,
                'contact_person_name' => ChatIdentity::displayName($recipient),
                'contact_person_mobile' => $mobile,
                'related_type' => 'Chat Message',
                'related_id' => $message->id,
                'sent_by' => SmsLog::SENT_BY_SYSTEM,
                'attempts' => $this->attempts(),
            ]);
            $this->smsLogId = $smsLog?->id;
        }

        $result = $smsProvider->sendSms($mobile, $body);

        if ($result['success'] ?? false) {
            $smsLogger->markSent(
                $smsLog,
                $result['message_id'] ?? null,
                $result['response'] ?? null,
                $this->attempts()
            );
            $this->mark($log, ChatNotificationLog::STATUS_SENT);

            return;
        }

        $smsLogger->markFailed(
            $smsLog,
            $result['error'] ?? 'Unknown',
            $result['response'] ?? null,
            $this->attempts()
        );

        throw new RuntimeException($result['error'] ?? 'Chat SMS send failed');
    }

    public function failed(?Throwable $exception = null): void
    {
        $log = ChatNotificationLog::query()->find($this->notificationLogId);
        if ($log && ! $log->isTerminal()) {
            $this->mark($log, ChatNotificationLog::STATUS_FAILED, Str::limit((string) $exception?->getMessage(), 500));
        }

        if ($this->smsLogId) {
            app(SmsLogger::class)->markFailed(
                SmsLog::query()->find($this->smsLogId),
                $exception?->getMessage() ?? 'Job failed permanently',
                null,
                $this->attempts()
            );
        }

        ChatLog::error('Chat SMS notification failed permanently', [
            'notification_log_id' => $this->notificationLogId,
            'sms_log_id' => $this->smsLogId,
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
