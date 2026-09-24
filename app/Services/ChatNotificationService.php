<?php

namespace App\Services;

use App\Contracts\SmsProvider;
use App\Jobs\SendChatEmailNotificationJob;
use App\Jobs\SendChatSmsNotificationJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatNotificationLog;
use App\Models\ChatUserSetting;
use App\Models\User;
use App\Support\ChatLog;
use App\Support\UserPresence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Throwable;

class ChatNotificationService
{
    /**
     * Fan out offline email/SMS for a persisted company-to-company message.
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

        if ($log === null || $log->isTerminal()) {
            return;
        }

        if ($log->status === ChatNotificationLog::STATUS_FAILED) {
            return;
        }

        try {
            SendChatEmailNotificationJob::dispatch((int) $log->id);
        } catch (Throwable $e) {
            ChatLog::error('Chat email notification dispatch failed', [
                'notification_log_id' => $log->id,
                'message_id' => $message->id,
                'recipient_user_id' => $recipient->id,
                'exception' => $e->getMessage(),
            ]);
        }
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
}
