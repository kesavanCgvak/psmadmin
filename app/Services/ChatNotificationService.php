<?php

namespace App\Services;

use App\Contracts\SmsProvider;
use App\Jobs\SendChatSmsNotificationJob;
use App\Mail\ChatMessageReceivedMail;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatNotificationLog;
use App\Models\ChatUserSetting;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\ChatIdentity;
use App\Support\ChatLog;
use App\Support\ChatUrls;
use App\Support\EmailLogContext;
use App\Support\UserPresence;
use Illuminate\Database\QueryException;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Throwable;

class ChatNotificationService
{
    /**
     * Fan out offline email/SMS for a persisted company-to-company message.
     * Email is sent during this call. SMS remains a queued job.
     * Recipients are always derived from the conversation on the backend.
     */
    public function processMessage(ChatMessage $message, ChatConversation $conversation, User $sender): void
    {
        try {
            $this->processMessageInner($message, $conversation, $sender);
        } catch (Throwable $e) {
            ChatLog::error('Chat notification processing failed', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'sender_user_id' => $sender->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function processMessageInner(ChatMessage $message, ChatConversation $conversation, User $sender): void
    {
        if ($message->trashed() || $message->deleted_at) {
            return;
        }

        $senderCompanyId = (int) $message->sender_company_id;
        $recipientCompanyId = (int) ($conversation->otherCompanyId($senderCompanyId) ?? 0);
        if ($recipientCompanyId <= 0 || ! $conversation->involvesCompany($senderCompanyId)) {
            return;
        }

        $recipients = $this->recipientUsers($recipientCompanyId, (int) $sender->id);
        if ($recipients->isEmpty()) {
            return;
        }

        $onlineUserIds = array_fill_keys(
            UserPresence::onlineUserIds($recipients->pluck('id')->all()),
            true
        );

        $conversation->loadMissing(['companyA', 'companyB']);
        $sender->loadMissing('profile');

        foreach ($recipients as $recipient) {
            try {
                if ((int) $recipient->id === (int) $sender->id) {
                    continue;
                }

                if ((int) ($recipient->company_id ?? 0) !== $recipientCompanyId) {
                    continue;
                }

                $isOnline = isset($onlineUserIds[(int) $recipient->id]);
                $settings = $this->settingsFor($recipient);

                $this->queueEmailIfNeeded($message, $conversation, $sender, $recipient, $settings, $isOnline);
                $this->queueSmsIfNeeded($message, $conversation, $sender, $recipient, $settings, $isOnline);
            } catch (Throwable $e) {
                ChatLog::error('Chat notification failed for recipient', [
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'recipient_user_id' => $recipient->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientUsers(int $recipientCompanyId, int $senderUserId): Collection
    {
        return User::query()
            ->where('company_id', $recipientCompanyId)
            ->where('id', '!=', $senderUserId)
            ->where(function ($query) {
                $query->where('is_blocked', false)->orWhereNull('is_blocked');
            })
            ->with(['profile', 'chatUserSetting'])
            ->get();
    }

    private function settingsFor(User $user): ChatUserSetting
    {
        if ($user->relationLoaded('chatUserSetting') && $user->chatUserSetting) {
            return $user->chatUserSetting;
        }

        return ChatUserSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'browser_notifications_enabled' => (bool) config('chat.defaults.browser_notifications_enabled', false),
                'email_notifications_enabled' => (bool) config('chat.defaults.email_notifications_enabled', true),
                'sms_notifications_enabled' => (bool) config('chat.defaults.sms_notifications_enabled', false),
            ]
        );
    }

    private function queueEmailIfNeeded(
        ChatMessage $message,
        ChatConversation $conversation,
        User $sender,
        User $recipient,
        ChatUserSetting $settings,
        bool $isOnline
    ): void {
        if ($isOnline) {
            return;
        }

        if (! $settings->email_notifications_enabled) {
            $this->recordSkip($recipient, $message, $conversation, ChatNotificationLog::CHANNEL_EMAIL, 'email_disabled');

            return;
        }

        $email = $this->recipientEmail($recipient);
        if ($email === null) {
            $this->recordSkip($recipient, $message, $conversation, ChatNotificationLog::CHANNEL_EMAIL, 'no_email');

            return;
        }

        if ($this->isThrottled($recipient, $conversation, ChatNotificationLog::CHANNEL_EMAIL)) {
            $this->claim(
                $recipient,
                $message,
                $conversation,
                ChatNotificationLog::CHANNEL_EMAIL,
                ChatNotificationLog::STATUS_THROTTLED,
                'throttled'
            );

            return;
        }

        $log = $this->claim(
            $recipient,
            $message,
            $conversation,
            ChatNotificationLog::CHANNEL_EMAIL,
            ChatNotificationLog::STATUS_PENDING
        );

        if ($log === null || $log->isTerminal() || $log->status === ChatNotificationLog::STATUS_FAILED) {
            return;
        }

        $this->transmitEmail($log, $message, $conversation, $sender, $recipient);
    }

    /**
     * Send one previously claimed email notification.
     * Used by leftover queued jobs. New messages call this path inline.
     */
    public function deliverEmailNotification(int $notificationLogId): void
    {
        $log = ChatNotificationLog::query()->find($notificationLogId);
        if (! $log || $log->channel !== ChatNotificationLog::CHANNEL_EMAIL) {
            return;
        }

        if ($log->isTerminal() || $log->status === ChatNotificationLog::STATUS_FAILED) {
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

        if ($this->recipientEmail($recipient) === null) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'no_email');

            return;
        }

        $this->transmitEmail($log, $message, $conversation, $sender, $recipient);
    }

    private function transmitEmail(
        ChatNotificationLog $log,
        ChatMessage $message,
        ChatConversation $conversation,
        User $sender,
        User $recipient
    ): void {
        $email = $this->recipientEmail($recipient);
        if ($email === null) {
            $this->mark($log, ChatNotificationLog::STATUS_SKIPPED, 'no_email');

            return;
        }

        $senderName = ChatIdentity::displayName($sender);
        $senderCompanyName = $this->companyName($conversation, (int) $message->sender_company_id) ?: 'a company';
        $preview = Str::limit(trim((string) $message->message), 80);
        $subject = sprintf('New message from %s at %s', $senderName, $senderCompanyName);

        $mailable = new ChatMessageReceivedMail(
            recipientName: ChatIdentity::displayName($recipient),
            senderName: $senderName,
            senderCompanyName: $senderCompanyName,
            preview: $preview,
            conversationUrl: ChatUrls::conversation((int) $conversation->id),
            conversationId: (int) $conversation->id,
            messageId: (int) $message->id,
        );

        try {
            EmailLogContext::begin('chat_message_received', [
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
                'recipient_user_id' => (int) $recipient->id,
            ]);

            $result = Mail::to($email)->send($mailable);
            $accepted = $result instanceof SentMessage || Mail::getFacadeRoot() instanceof MailFake;

            if (! $accepted) {
                $reason = 'The mail transport did not accept the message.';
                $this->recordChatEmailFailure($email, $subject, $reason, $recipient, $message);
                $this->mark($log, ChatNotificationLog::STATUS_FAILED, $reason);
                ChatLog::error('Chat email notification was not accepted', [
                    'notification_log_id' => $log->id,
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'recipient_user_id' => $recipient->id,
                ]);

                return;
            }

            $this->confirmEmailLogAccepted($email, $subject, $message);
            $this->mark($log, ChatNotificationLog::STATUS_SENT);
        } catch (Throwable $e) {
            $reason = Str::limit($e->getMessage(), 500);
            $this->recordChatEmailFailure($email, $subject, $e->getMessage(), $recipient, $message);
            $this->mark($log, ChatNotificationLog::STATUS_FAILED, $reason);
            ChatLog::error('Chat email notification failed', [
                'notification_log_id' => $log->id,
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'recipient_user_id' => $recipient->id,
                'exception' => $e->getMessage(),
            ]);
        } finally {
            EmailLogContext::clear();
        }
    }

    private function confirmEmailLogAccepted(string $toEmail, string $subject, ChatMessage $message): void
    {
        try {
            $existing = $this->recentChatEmailLog($toEmail, $subject, (int) $message->id);
            if (! $existing || $existing->status !== EmailLog::STATUS_PENDING) {
                return;
            }

            if (! $existing->reference_id) {
                $existing->reference_id = $message->id;
            }

            $existing->recordSubmittedToTransport();
        } catch (Throwable $e) {
            ChatLog::warning('Chat email log could not be marked sent', [
                'message_id' => $message->id,
                'to_email' => $toEmail,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function recordChatEmailFailure(
        string $toEmail,
        string $subject,
        string $reason,
        User $recipient,
        ChatMessage $message
    ): void {
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'The mail transport rejected the message.';
        }

        try {
            $existing = $this->recentChatEmailLog($toEmail, $subject, (int) $message->id);

            if ($existing && (int) $existing->reference_id === (int) $message->id) {
                if ($existing->status !== EmailLog::STATUS_SENT) {
                    $existing->recordTransportFailure($reason);
                }

                return;
            }

            if ($existing && $existing->status === EmailLog::STATUS_PENDING && ! $existing->reference_id) {
                $existing->reference_id = $message->id;
                $existing->recordTransportFailure($reason);

                return;
            }

            EmailLog::create([
                'from_email' => config('mail.from.address'),
                'to_email' => $toEmail,
                'subject' => $subject,
                'email_type' => EmailLog::TYPE_CHAT_NOTIFICATION,
                'status' => EmailLog::STATUS_FAILED,
                'failure_reason' => mb_substr($reason, 0, 2000),
                'related_user_id' => $recipient->id,
                'reference_id' => $message->id,
                'mail_class' => ChatMessageReceivedMail::class,
                'payload_snapshot' => [
                    'template' => 'chat_message_received',
                    'data' => [
                        'conversation_id' => (int) $message->conversation_id,
                        'message_id' => (int) $message->id,
                        'recipient_user_id' => (int) $recipient->id,
                    ],
                    'sensitive' => false,
                    'to' => [$toEmail],
                    'cc' => [],
                    'bcc' => [],
                    'reply_to' => [],
                    'html' => null,
                    'text' => null,
                    'attachments' => [],
                ],
            ]);
        } catch (Throwable $e) {
            ChatLog::error('Chat email log could not be marked failed', [
                'message_id' => $message->id,
                'to_email' => $toEmail,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function recentChatEmailLog(string $toEmail, string $subject, int $messageId): ?EmailLog
    {
        $byReference = EmailLog::query()
            ->where('to_email', $toEmail)
            ->where('reference_id', $messageId)
            ->where('mail_class', ChatMessageReceivedMail::class)
            ->orderByDesc('id')
            ->first();

        if ($byReference) {
            return $byReference;
        }

        return EmailLog::query()
            ->where('to_email', $toEmail)
            ->where('subject', $subject)
            ->where('created_at', '>=', now()->subSeconds(15))
            ->orderByDesc('id')
            ->first();
    }

    private function queueSmsIfNeeded(
        ChatMessage $message,
        ChatConversation $conversation,
        User $sender,
        User $recipient,
        ChatUserSetting $settings,
        bool $isOnline
    ): void {
        if ($isOnline) {
            return;
        }

        if (! $settings->sms_notifications_enabled || $settings->sms_consented_at === null) {
            $this->recordSkip($recipient, $message, $conversation, ChatNotificationLog::CHANNEL_SMS, 'sms_disabled');

            return;
        }

        $mobile = trim((string) ($recipient->profile?->mobile ?? ''));
        $smsProvider = app(SmsProvider::class);
        if ($mobile === '' || ! $smsProvider->isValidMobile($mobile)) {
            $this->recordSkip($recipient, $message, $conversation, ChatNotificationLog::CHANNEL_SMS, 'no_mobile');

            return;
        }

        if ($this->isThrottled($recipient, $conversation, ChatNotificationLog::CHANNEL_SMS)) {
            $this->claim(
                $recipient,
                $message,
                $conversation,
                ChatNotificationLog::CHANNEL_SMS,
                ChatNotificationLog::STATUS_THROTTLED,
                'throttled'
            );

            return;
        }

        $log = $this->claim(
            $recipient,
            $message,
            $conversation,
            ChatNotificationLog::CHANNEL_SMS,
            ChatNotificationLog::STATUS_PENDING
        );

        if ($log === null || $log->isTerminal() || $log->status === ChatNotificationLog::STATUS_FAILED) {
            return;
        }

        try {
            SendChatSmsNotificationJob::dispatch((int) $log->id);
        } catch (Throwable $e) {
            ChatLog::error('Chat SMS notification dispatch failed', [
                'notification_log_id' => $log->id,
                'message_id' => $message->id,
                'recipient_user_id' => $recipient->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function recipientEmail(User $user): ?string
    {
        $email = trim((string) ($user->preferred_email ?: $user->email ?: ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    private function isThrottled(User $user, ChatConversation $conversation, string $channel): bool
    {
        $seconds = (int) config('chat.notification_throttle_seconds', 300);
        if ($seconds <= 0) {
            return false;
        }

        return ChatNotificationLog::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->where('channel', $channel)
            ->where('status', ChatNotificationLog::STATUS_SENT)
            ->where('processed_at', '>=', now()->subSeconds($seconds))
            ->exists();
    }

    private function recordSkip(
        User $user,
        ChatMessage $message,
        ChatConversation $conversation,
        string $channel,
        string $reason
    ): void {
        $this->claim(
            $user,
            $message,
            $conversation,
            $channel,
            ChatNotificationLog::STATUS_SKIPPED,
            $reason
        );
    }

    private function claim(
        User $user,
        ChatMessage $message,
        ChatConversation $conversation,
        string $channel,
        string $status,
        ?string $errorMessage = null
    ): ?ChatNotificationLog {
        $attributes = [
            'user_id' => $user->id,
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'channel' => $channel,
        ];

        try {
            $log = ChatNotificationLog::query()->firstOrCreate($attributes, [
                'status' => $status,
                'error_message' => $errorMessage,
                'processed_at' => $status === ChatNotificationLog::STATUS_PENDING ? null : now(),
            ]);
        } catch (QueryException $e) {
            $log = ChatNotificationLog::query()
                ->where('user_id', $user->id)
                ->where('message_id', $message->id)
                ->where('channel', $channel)
                ->first();

            if (! $log) {
                ChatLog::warning('Chat notification claim failed', [
                    'user_id' => $user->id,
                    'message_id' => $message->id,
                    'channel' => $channel,
                    'exception' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if ($log->wasRecentlyCreated) {
            return $log;
        }

        if ($log->isTerminal()) {
            return $log;
        }

        if ($status !== ChatNotificationLog::STATUS_PENDING && $log->status === ChatNotificationLog::STATUS_PENDING) {
            $log->forceFill([
                'status' => $status,
                'error_message' => $errorMessage,
                'processed_at' => now(),
            ])->save();
        }

        return $log;
    }

    private function mark(ChatNotificationLog $log, string $status, ?string $error = null): void
    {
        $log->forceFill([
            'status' => $status,
            'error_message' => $error,
            'processed_at' => now(),
        ])->save();
    }

    private function companyName(ChatConversation $conversation, int $companyId): ?string
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
