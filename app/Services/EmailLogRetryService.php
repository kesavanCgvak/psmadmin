<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailLogRetryService
{
    public const SUBMITTED_MESSAGE = 'The email was submitted to the mail transport. This does not confirm that the recipient received it.';

    /**
     * Resend a pending or failed log through the same mailer used by the rest of the app.
     * The log is not a queued job. Content is taken from the snapshot captured when the
     * message was first handed to the mailer. Status becomes sent only after that send
     * is accepted, which is the same point MessageSent already uses.
     *
     * @return array{success: bool, message: string, status_code: int}
     */
    public function retry(EmailLog $emailLog): array
    {
        $claimed = DB::transaction(function () use ($emailLog) {
            $log = EmailLog::query()->whereKey($emailLog->id)->lockForUpdate()->first();

            if (! $log) {
                return [
                    'success' => false,
                    'message' => 'Email log not found.',
                    'status_code' => 404,
                ];
            }

            $rejection = $this->rejection($log);
            if ($rejection !== null) {
                return $rejection;
            }

            $log->claimForRetry();

            return [
                'success' => true,
                'log' => $log->fresh(),
            ];
        });

        if (! ($claimed['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $claimed['message'],
                'status_code' => $claimed['status_code'],
            ];
        }

        /** @var EmailLog $log */
        $log = $claimed['log'];

        try {
            $sent = $this->sendStoredMessage($log);
        } catch (Throwable $e) {
            Log::warning('Email log retry failed', [
                'email_log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);

            $log->refresh();
            $log->recordTransportFailure($e->getMessage());

            return [
                'success' => false,
                'message' => 'The email could not be submitted to the mail transport. '.$this->safeError($e->getMessage()),
                'status_code' => 422,
            ];
        }

        if (! $sent) {
            $log->refresh();
            if ($log->status !== EmailLog::STATUS_SENT) {
                $log->recordTransportFailure('The mail transport did not accept the message.');
            }

            return [
                'success' => false,
                'message' => 'The mail transport did not accept the message.',
                'status_code' => 422,
            ];
        }

        // Mail::send returns only after the transport accepts the message.
        // MessageSent records that same outcome; this clears the retry lock if the listener already did.
        $log->refresh();
        $log->recordSubmittedToTransport();

        return [
            'success' => true,
            'message' => self::SUBMITTED_MESSAGE,
            'status_code' => 200,
        ];
    }

    /**
     * @return array{success: bool, message: string, status_code: int}|null
     */
    private function rejection(EmailLog $log): ?array
    {
        if ($log->status === EmailLog::STATUS_SENT) {
            return [
                'success' => false,
                'message' => 'Sent emails cannot be retried.',
                'status_code' => 422,
            ];
        }

        if (! $log->acceptsRetryAction()) {
            return [
                'success' => false,
                'message' => 'This email cannot be retried.',
                'status_code' => 422,
            ];
        }

        if ($log->isRetryInProgress()) {
            return [
                'success' => false,
                'message' => 'A retry is already in progress for this email.',
                'status_code' => 409,
            ];
        }

        if ($log->status === EmailLog::STATUS_PENDING && $log->isOriginalAttemptInFlight()) {
            return [
                'success' => false,
                'message' => 'This email is still being processed and cannot be retried yet.',
                'status_code' => 409,
            ];
        }

        $snapshot = $log->payload_snapshot ?? [];

        if (! $log->hasStoredContent()) {
            $message = $snapshot['content_omit_reason']
                ?? 'This email cannot be retried because its message content was not stored.';

            return [
                'success' => false,
                'message' => $message,
                'status_code' => 422,
            ];
        }

        if (! empty($snapshot['attachments_incomplete'])) {
            return [
                'success' => false,
                'message' => 'This email cannot be retried because an attachment could not be stored with the log.',
                'status_code' => 422,
            ];
        }

        return null;
    }

    private function sendStoredMessage(EmailLog $log): bool
    {
        $snapshot = $log->payload_snapshot ?? [];
        $html = $snapshot['html'] ?? null;
        $text = $snapshot['text'] ?? null;
        $recipients = array_values(array_filter($snapshot['to'] ?? []));

        if ($recipients === [] && $log->to_email) {
            $recipients = [$log->to_email];
        }

        $sentMessage = Mail::send([], [], function ($message) use ($log, $snapshot, $html, $text, $recipients) {
            $message->to($recipients);
            $message->subject($log->subject ?? '');

            if ($log->from_email) {
                $message->from($log->from_email);
            }

            if (is_string($html) && $html !== '') {
                $message->html($html);
            }

            if (is_string($text) && $text !== '') {
                $message->text($text);
            }

            foreach ($snapshot['cc'] ?? [] as $address) {
                if (is_string($address) && $address !== '') {
                    $message->cc($address);
                }
            }

            foreach ($snapshot['bcc'] ?? [] as $address) {
                if (is_string($address) && $address !== '') {
                    $message->bcc($address);
                }
            }

            foreach ($snapshot['reply_to'] ?? [] as $address) {
                if (is_string($address) && $address !== '') {
                    $message->replyTo($address);
                }
            }

            foreach ($snapshot['attachments'] ?? [] as $attachment) {
                $encoded = $attachment['content'] ?? '';
                $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
                if ($bytes === false) {
                    throw new \RuntimeException('Stored attachment content could not be read.');
                }

                $message->attachData($bytes, (string) ($attachment['filename'] ?? 'attachment'), [
                    'mime' => (string) ($attachment['mime'] ?? 'application/octet-stream'),
                ]);
            }

            $headers = $message->getHeaders();
            if (! $headers->has(EmailLog::LOG_ID_HEADER)) {
                $headers->addTextHeader(EmailLog::LOG_ID_HEADER, (string) $log->id);
            }

            if ($log->mail_class && ! $headers->has(EmailLog::MAIL_CLASS_HEADER)) {
                $headers->addTextHeader(EmailLog::MAIL_CLASS_HEADER, $log->mail_class);
            }

            if ($log->email_type && ! $headers->has(EmailLog::EMAIL_TYPE_HEADER)) {
                $headers->addTextHeader(EmailLog::EMAIL_TYPE_HEADER, $log->email_type);
            }
        });

        return $sentMessage !== null;
    }

    private function safeError(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'The mail transport returned an error.';
        }

        return mb_substr($message, 0, 300);
    }
}
