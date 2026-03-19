<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSending;

class LogEmailSending
{
    /**
     * Handle the MessageSending event.
     * Creates a pending log entry before the email is sent.
     */
    public function handle(MessageSending $event): void
    {
        try {
            $message = $event->message;

            $fromEmail = $this->extractFirstAddress($message->getFrom());
            $toEmail = $this->extractFirstAddress($message->getTo());
            $subject = $message->getSubject();

            if (!$toEmail) {
                return;
            }

            // Deduplicate: EmailHelper (and similar) may retry/fallback on failure, causing
            // MessageSending to fire twice for the same logical email. Reuse a recent pending
            // log for the same to_email + subject instead of creating a duplicate.
            $existingLog = EmailLog::where('to_email', $toEmail)
                ->where('subject', $subject)
                ->where('status', EmailLog::STATUS_PENDING)
                ->where('created_at', '>=', now()->subSeconds(10))
                ->orderByDesc('id')
                ->first();

            if ($existingLog) {
                $log = $existingLog;
            } else {
                $mailClass = $this->extractMailClass($message);
                $emailType = EmailLog::inferEmailType($subject, $mailClass);
                $relatedUserId = $this->resolveRelatedUserId($toEmail);

                $log = EmailLog::create([
                    'from_email' => $fromEmail,
                    'to_email' => $toEmail,
                    'subject' => $subject,
                    'email_type' => $emailType,
                    'status' => EmailLog::STATUS_PENDING,
                    'mail_class' => $mailClass ?: null,
                    'payload_snapshot' => $this->getPayloadSnapshot($message),
                    'related_user_id' => $relatedUserId,
                ]);
            }

            $message->getHeaders()->addTextHeader(EmailLog::LOG_ID_HEADER, (string) $log->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Email log (sending) failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function extractFirstAddress(?array $addresses): ?string
    {
        if (empty($addresses)) {
            return null;
        }

        $first = $addresses[0] ?? null;
        if (is_object($first) && method_exists($first, 'getAddress')) {
            return $first->getAddress();
        }

        if (is_string($first)) {
            return $first;
        }

        return null;
    }

    private function extractMailClass($message): ?string
    {
        return null;
    }

    /**
     * Get a lightweight payload snapshot (avoid storing large/sensitive data).
     */
    private function getPayloadSnapshot($message): ?array
    {
        return null;
    }

    private function resolveRelatedUserId(string $toEmail): ?int
    {
        $user = \App\Models\User::where('email', $toEmail)->first();
        if ($user) {
            return $user->id;
        }

        $profile = \App\Models\UserProfile::where('email', $toEmail)->first();
        if ($profile && $profile->user_id) {
            return $profile->user_id;
        }

        return null;
    }
}
