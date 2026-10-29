<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Support\EmailLogContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailLogRetryService
{
    public const SUBMITTED_MESSAGE = 'The email was submitted to the mail transport. This does not confirm that the recipient received it.';

    public const RATE_LIMIT_CACHE_KEY = 'email-log-retry:rate-limited-until';

    public const RATE_LIMIT_MESSAGE = 'The hosting server rejected the message because the hourly email limit was reached. It was not sent. Further retries are paused so more messages are not discarded.';

    /**
     * Resend one pending or failed log through the existing mailer.
     * A stored body is sent as-is. Otherwise the saved template and data are rendered
     * with EmailTemplateService. Nothing is sent when that information is missing.
     * Status becomes sent only after the transport accepts the message.
     *
     * @return array{success: bool, message: string, status_code: int, alternative: ?array{url: string, label: string}}
     */
    public function retry(EmailLog $emailLog): array
    {
        $emailLog->refresh();

        $blocked = $this->preflight($emailLog);
        if ($blocked !== null) {
            return $blocked;
        }

        $prepared = $this->prepareOutgoing($emailLog);
        if (! $prepared['ok']) {
            return $this->failure($prepared['message'], 422, $emailLog);
        }

        $claimed = DB::transaction(function () use ($emailLog) {
            $log = EmailLog::query()->whereKey($emailLog->id)->lockForUpdate()->first();

            if (! $log) {
                return $this->failure('Email log not found.', 404, $emailLog);
            }

            $blocked = $this->preflight($log);
            if ($blocked !== null) {
                return $blocked;
            }

            $log->claimForRetry();

            return [
                'success' => true,
                'log' => $log->fresh(),
            ];
        });

        if (! ($claimed['success'] ?? false)) {
            return $claimed;
        }

        /** @var EmailLog $log */
        $log = $claimed['log'];

        try {
            $sent = $this->sendPrepared($log, $prepared);
        } catch (Throwable $e) {
            Log::warning('Email log retry failed', [
                'email_log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);

            $log->refresh();
            $log->recordTransportFailure($e->getMessage());

            if (EmailLogContext::isRateLimitError($e->getMessage())) {
                $this->pauseAfterRateLimit();

                return $this->failure(self::RATE_LIMIT_MESSAGE, 429, $log);
            }

            return $this->failure(
                'The email could not be submitted to the mail transport. '.$this->safeError($e->getMessage()),
                422,
                $log
            );
        }

        if (! $sent) {
            $log->refresh();
            if ($log->status !== EmailLog::STATUS_SENT) {
                $log->recordTransportFailure('The mail transport did not accept the message.');
            }

            return $this->failure('The mail transport did not accept the message.', 422, $log);
        }

        // Mail::send returns only after the transport accepts the message.
        // MessageSent records that same outcome; this clears the retry lock if the listener already did.
        $log->refresh();
        $log->recordSubmittedToTransport();

        return [
            'success' => true,
            'message' => self::SUBMITTED_MESSAGE,
            'status_code' => 200,
            'alternative' => null,
        ];
    }

    /**
     * @return array{success: bool, message: string, status_code: int, alternative: ?array{url: string, label: string}}|null
     */
    private function preflight(EmailLog $log): ?array
    {
        if ($log->status === EmailLog::STATUS_SENT) {
            return $this->failure('Sent emails cannot be retried.', 422, $log);
        }

        if (! $log->acceptsRetryAction()) {
            return $this->failure('This email cannot be retried.', 422, $log);
        }

        if ($log->isRetryInProgress()) {
            return $this->failure('A retry is already in progress for this email.', 409, $log);
        }

        if ($log->status === EmailLog::STATUS_PENDING && $log->isOriginalAttemptInFlight()) {
            return $this->failure('This email is still being processed and cannot be retried yet.', 409, $log);
        }

        $rateLimit = $this->rateLimitBlock();
        if ($rateLimit !== null) {
            return $this->failure($rateLimit, 429, $log);
        }

        if (! $log->hasRetryablePayload()) {
            return $this->failure($log->permanentRetryBlockReason() ?? EmailLog::HISTORICAL_RETRY_MESSAGE, 422, $log);
        }

        return null;
    }

    /**
     * @return array{ok: true, html: ?string, text: ?string, attachments: array<int, mixed>}|array{ok: false, message: string}
     */
    private function prepareOutgoing(EmailLog $log): array
    {
        $snapshot = $log->payload_snapshot ?? [];
        $html = is_string($snapshot['html'] ?? null) ? $snapshot['html'] : null;
        $text = is_string($snapshot['text'] ?? null) ? $snapshot['text'] : null;
        $attachments = is_array($snapshot['attachments'] ?? null) ? $snapshot['attachments'] : [];

        if (trim((string) $html) !== '' || trim((string) $text) !== '') {
            return [
                'ok' => true,
                'html' => $html,
                'text' => $text,
                'attachments' => $attachments,
            ];
        }

        $template = $snapshot['template'] ?? null;
        $data = $snapshot['data'] ?? null;

        if (! is_string($template) || $template === '' || ! is_array($data)) {
            return [
                'ok' => false,
                'message' => EmailLog::HISTORICAL_RETRY_MESSAGE,
            ];
        }

        $rendered = app(EmailTemplateService::class)->getTemplate($template, $data);
        $body = is_array($rendered) ? trim((string) ($rendered['body'] ?? '')) : '';

        if ($body === '') {
            return [
                'ok' => false,
                'message' => 'This email cannot be reconstructed because template "'.$template.'" is not available. No message was sent.',
            ];
        }

        return [
            'ok' => true,
            'html' => $body,
            'text' => null,
            'attachments' => $attachments,
        ];
    }

    /**
     * @param  array{ok: true, html: ?string, text: ?string, attachments: array<int, mixed>}  $prepared
     */
    private function sendPrepared(EmailLog $log, array $prepared): bool
    {
        $snapshot = $log->payload_snapshot ?? [];
        $html = $prepared['html'];
        $text = $prepared['text'];
        $recipients = array_values(array_filter($snapshot['to'] ?? []));

        if ($recipients === [] && $log->to_email) {
            $recipients = [$log->to_email];
        }

        if ($recipients === [] || ! filter_var($recipients[0], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('The stored recipient is not a valid email address.');
        }

        $sentMessage = Mail::send([], [], function ($message) use ($log, $snapshot, $html, $text, $recipients, $prepared) {
            $message->to($recipients);
            $message->subject($log->subject ?? '');

            if ($log->from_email && filter_var($log->from_email, FILTER_VALIDATE_EMAIL)) {
                $message->from($log->from_email);
            }

            if (is_string($html) && $html !== '') {
                $message->html($html);
            }

            if (is_string($text) && $text !== '') {
                $message->text($text);
            }

            foreach ($snapshot['cc'] ?? [] as $address) {
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $message->cc($address);
                }
            }

            foreach ($snapshot['bcc'] ?? [] as $address) {
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $message->bcc($address);
                }
            }

            foreach ($snapshot['reply_to'] ?? [] as $address) {
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $message->replyTo($address);
                }
            }

            foreach ($prepared['attachments'] as $attachment) {
                if (! is_array($attachment)) {
                    throw new \RuntimeException('Stored attachment content could not be read.');
                }

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

    private function rateLimitBlock(): ?string
    {
        $until = Cache::get(self::RATE_LIMIT_CACHE_KEY);

        if (! is_string($until) || $until === '') {
            return null;
        }

        if (now()->greaterThan(Carbon::parse($until))) {
            return null;
        }

        return 'Email retries are paused until '.$until.' because the hosting server reached its hourly email limit. No message was sent.';
    }

    private function pauseAfterRateLimit(): void
    {
        $seconds = max(60, (int) config('mail.retry.rate_limit_cooldown_seconds', 900));
        $until = now()->addSeconds($seconds)->toIso8601String();

        Cache::put(self::RATE_LIMIT_CACHE_KEY, $until, $seconds);
    }

    /**
     * @return array{success: false, message: string, status_code: int, alternative: ?array{url: string, label: string}}
     */
    private function failure(string $message, int $statusCode, EmailLog $log): array
    {
        return [
            'success' => false,
            'message' => $message,
            'status_code' => $statusCode,
            'alternative' => $this->alternative($log),
        ];
    }

    /**
     * @return array{url: string, label: string}|null
     */
    private function alternative(EmailLog $log): ?array
    {
        if ($log->hasRetryablePayload() || ! $log->related_user_id) {
            return null;
        }

        return [
            'url' => route('admin.users.show', $log->related_user_id),
            'label' => 'View related user',
        ];
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
