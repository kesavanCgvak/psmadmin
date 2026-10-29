<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

class LogEmailSending
{
    private const MAX_BODY_BYTES = 500000;

    private const MAX_ATTACHMENT_BYTES = 262144;

    private const MAX_ATTACHMENTS = 5;

    /**
     * Handle the MessageSending event.
     * Creates a pending log entry before the email is sent.
     * A retry reuses the log identified by X-Email-Log-Id instead of inserting another row.
     */
    public function handle(MessageSending $event): void
    {
        try {
            $message = $event->message;
            $explicitLogId = $this->extractHeaderValue($message, EmailLog::LOG_ID_HEADER);

            if ($explicitLogId && $existing = EmailLog::find($explicitLogId)) {
                $this->syncLogFromMessage($existing, $message);

                return;
            }

            $fromEmail = $this->extractFirstAddress($message->getFrom());
            $toEmail = $this->extractFirstAddress($message->getTo());
            $subject = $message->getSubject();
            $explicitEmailType = $this->extractHeaderValue($message, EmailLog::EMAIL_TYPE_HEADER);

            if (! $toEmail) {
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
                $this->syncLogFromMessage($log, $message);
            } else {
                $mailClass = $this->extractMailClass($message);
                $emailType = $explicitEmailType ?: EmailLog::inferEmailType($subject, $mailClass);
                $relatedUserId = $this->resolveRelatedUserId($toEmail);

                $log = EmailLog::create([
                    'from_email' => $fromEmail,
                    'to_email' => $toEmail,
                    'subject' => $subject,
                    'email_type' => $emailType,
                    'status' => EmailLog::STATUS_PENDING,
                    'mail_class' => $mailClass ?: null,
                    'payload_snapshot' => $this->buildSnapshot($message, null),
                    'related_user_id' => $relatedUserId,
                ]);
            }

            if (! $message->getHeaders()->has(EmailLog::LOG_ID_HEADER)) {
                $message->getHeaders()->addTextHeader(EmailLog::LOG_ID_HEADER, (string) $log->id);
            }
        } catch (\Throwable $e) {
            Log::error('Email log (sending) failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Refresh envelope and stored content without changing status.
     * Retry bookkeeping already on the snapshot is preserved.
     */
    private function syncLogFromMessage(EmailLog $log, $message): void
    {
        $toEmail = $this->extractFirstAddress($message->getTo());

        $log->fill([
            'from_email' => $this->extractFirstAddress($message->getFrom()) ?: $log->from_email,
            'to_email' => $toEmail ?: $log->to_email,
            'subject' => $message->getSubject() ?: $log->subject,
            'payload_snapshot' => $this->buildSnapshot($message, $log->payload_snapshot),
        ])->save();
    }

    private function extractFirstAddress(?array $addresses): ?string
    {
        $all = $this->extractAddresses($addresses);

        return $all[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function extractAddresses(?array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        $result = [];

        foreach ($addresses as $address) {
            if (is_object($address) && method_exists($address, 'getAddress')) {
                $value = $address->getAddress();
            } elseif (is_string($address)) {
                $value = $address;
            } else {
                continue;
            }

            if (is_string($value) && $value !== '') {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function extractMailClass($message): ?string
    {
        return $this->extractHeaderValue($message, EmailLog::MAIL_CLASS_HEADER);
    }

    private function extractHeaderValue($message, string $headerName): ?string
    {
        $headers = $message->getHeaders();
        if (! $headers->has($headerName)) {
            return null;
        }

        $header = $headers->get($headerName);
        $value = $header && method_exists($header, 'getBodyAsString')
            ? trim($header->getBodyAsString())
            : null;

        return $value !== '' ? $value : null;
    }

    /**
     * Store the rendered message so an admin retry can resend it.
     * The original send path does not keep template data or a queue payload.
     *
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function buildSnapshot($message, ?array $existing): array
    {
        $existing = $existing ?? [];
        $html = $this->normalizeBody(method_exists($message, 'getHtmlBody') ? $message->getHtmlBody() : null);
        $text = $this->normalizeBody(method_exists($message, 'getTextBody') ? $message->getTextBody() : null);

        $snapshot = [
            'to' => $this->extractAddresses(method_exists($message, 'getTo') ? $message->getTo() : null),
            'cc' => $this->extractAddresses(method_exists($message, 'getCc') ? $message->getCc() : null),
            'bcc' => $this->extractAddresses(method_exists($message, 'getBcc') ? $message->getBcc() : null),
            'reply_to' => $this->extractAddresses(method_exists($message, 'getReplyTo') ? $message->getReplyTo() : null),
            'html' => $html,
            'text' => $text,
            'attachments' => [],
        ];

        $bodyTooLarge = ($html !== null && strlen($html) > self::MAX_BODY_BYTES)
            || ($text !== null && strlen($text) > self::MAX_BODY_BYTES);

        if ($bodyTooLarge) {
            $snapshot['html'] = null;
            $snapshot['text'] = null;
            $snapshot['content_omit_reason'] = 'Message body exceeded the storage limit and was not kept for retry.';
        } elseif ($html === null && $text === null) {
            $snapshot['html'] = $existing['html'] ?? null;
            $snapshot['text'] = $existing['text'] ?? null;
            if (isset($existing['content_omit_reason'])) {
                $snapshot['content_omit_reason'] = $existing['content_omit_reason'];
            }
        }

        $attachments = $this->extractAttachments($message);
        if ($attachments['incomplete']) {
            $snapshot['attachments'] = [];
            $snapshot['attachments_incomplete'] = true;
        } elseif ($attachments['items'] === [] && ! empty($existing['attachments']) && $html === null && $text === null) {
            $snapshot['attachments'] = $existing['attachments'];
            if (! empty($existing['attachments_incomplete'])) {
                $snapshot['attachments_incomplete'] = true;
            }
        } else {
            $snapshot['attachments'] = $attachments['items'];
        }

        if (isset($existing['retry']) && is_array($existing['retry'])) {
            $snapshot['retry'] = $existing['retry'];
        }

        return $snapshot;
    }

    /**
     * @return array{items: list<array{filename: string, mime: string, content: string}>, incomplete: bool}
     */
    private function extractAttachments($message): array
    {
        if (! method_exists($message, 'getAttachments')) {
            return ['items' => [], 'incomplete' => false];
        }

        $items = [];

        foreach ($message->getAttachments() as $part) {
            if (count($items) >= self::MAX_ATTACHMENTS) {
                return ['items' => [], 'incomplete' => true];
            }

            $raw = $this->attachmentBytes($part);
            if (! is_string($raw) || strlen($raw) > self::MAX_ATTACHMENT_BYTES) {
                return ['items' => [], 'incomplete' => true];
            }

            $filename = 'attachment';
            if (is_object($part) && method_exists($part, 'getFilename') && $part->getFilename()) {
                $filename = (string) $part->getFilename();
            }

            $mime = 'application/octet-stream';
            if (is_object($part) && method_exists($part, 'getContentType') && $part->getContentType()) {
                $mime = (string) $part->getContentType();
            }

            $items[] = [
                'filename' => $filename,
                'mime' => $mime,
                'content' => base64_encode($raw),
            ];
        }

        return ['items' => $items, 'incomplete' => false];
    }

    private function attachmentBytes(mixed $part): ?string
    {
        if (! is_object($part) || ! method_exists($part, 'getBody')) {
            return null;
        }

        // getBody() is the raw bytes. bodyToString() is the transfer-encoded form.
        $raw = $part->getBody();
        if (is_resource($raw)) {
            $raw = stream_get_contents($raw);
        }

        return is_string($raw) ? $raw : null;
    }

    private function normalizeBody(mixed $body): ?string
    {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        if (! is_string($body) || trim($body) === '') {
            return null;
        }

        return $body;
    }

    private function resolveRelatedUserId(string $toEmail): ?int
    {
        $user = User::where('email', $toEmail)->first();
        if ($user) {
            return $user->id;
        }

        $profile = UserProfile::where('email', $toEmail)->first();
        if ($profile && $profile->user_id) {
            return $profile->user_id;
        }

        return null;
    }
}
