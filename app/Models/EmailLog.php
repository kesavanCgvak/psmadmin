<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EmailLog extends Model
{
    protected $fillable = [
        'from_email',
        'to_email',
        'subject',
        'email_type',
        'status',
        'failure_reason',
        'related_user_id',
        'reference_id',
        'mail_class',
        'payload_snapshot',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * Ignore another retry while a claimed attempt is still inside this window.
     */
    public const RETRY_LOCK_SECONDS = 120;

    /**
     * A brand-new pending log may still be inside the original send.
     */
    public const ORIGINAL_SEND_GRACE_SECONDS = 90;

    public const TYPE_PSM_PRODUCT_SUBMISSION = 'PSM Product Submission';

    /**
     * Header name used to pass email log ID between MessageSending and MessageSent events.
     */
    public const LOG_ID_HEADER = 'X-Email-Log-Id';

    public const EMAIL_TYPE_HEADER = 'X-Email-Type';

    public const MAIL_CLASS_HEADER = 'X-Mail-Class';

    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }

    /**
     * Pending and failed logs are the only statuses the admin retry action applies to.
     */
    public function acceptsRetryAction(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true);
    }

    /**
     * Reason the retry control should be disabled, or null when the admin may submit it.
     * Missing content is still submittable so the server can explain why that log cannot be resent.
     */
    public function temporaryRetryBlockReason(): ?string
    {
        if ($this->isRetryInProgress()) {
            return 'A retry is already in progress for this email.';
        }

        if ($this->status === self::STATUS_PENDING && $this->isOriginalAttemptInFlight()) {
            return 'This email is still being processed and cannot be retried yet.';
        }

        return null;
    }

    public function isRetryInProgress(): bool
    {
        $retry = $this->retryMeta();

        if (! $retry['in_progress'] || ! $retry['started_at']) {
            return false;
        }

        return Carbon::parse($retry['started_at'])->greaterThan(now()->subSeconds(self::RETRY_LOCK_SECONDS));
    }

    /**
     * The first send is synchronous. A very recent pending row may still be talking to the mail transport.
     */
    public function isOriginalAttemptInFlight(): bool
    {
        if ($this->status !== self::STATUS_PENDING || $this->retryCount() > 0) {
            return false;
        }

        $updatedAt = $this->updated_at;

        if (! $updatedAt) {
            return false;
        }

        return $updatedAt->greaterThan(now()->subSeconds(self::ORIGINAL_SEND_GRACE_SECONDS));
    }

    public function retryCount(): int
    {
        return $this->retryMeta()['count'];
    }

    public function hasStoredContent(): bool
    {
        $snapshot = $this->payload_snapshot ?? [];

        foreach (['html', 'text'] as $key) {
            if (trim((string) ($snapshot[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{count: int, in_progress: bool, started_at: ?string, last_attempt_at: ?string}
     */
    public function retryMeta(): array
    {
        $retry = $this->payload_snapshot['retry'] ?? [];

        return [
            'count' => (int) ($retry['count'] ?? 0),
            'in_progress' => (bool) ($retry['in_progress'] ?? false),
            'started_at' => isset($retry['started_at']) ? (string) $retry['started_at'] : null,
            'last_attempt_at' => isset($retry['last_attempt_at']) ? (string) $retry['last_attempt_at'] : null,
        ];
    }

    public function claimForRetry(): void
    {
        $snapshot = $this->payload_snapshot ?? [];
        $retry = $snapshot['retry'] ?? [];
        $now = now()->toIso8601String();

        $retry['count'] = (int) ($retry['count'] ?? 0) + 1;
        $retry['in_progress'] = true;
        $retry['started_at'] = $now;
        $retry['last_attempt_at'] = $now;
        $snapshot['retry'] = $retry;

        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'failure_reason' => null,
            'payload_snapshot' => $snapshot,
        ])->save();
    }

    /**
     * The mail transport accepted the message. This is not a delivery receipt.
     */
    public function recordSubmittedToTransport(): void
    {
        $snapshot = $this->payload_snapshot ?? [];

        if (isset($snapshot['retry']) && is_array($snapshot['retry'])) {
            $snapshot['retry']['in_progress'] = false;
            $snapshot['retry']['started_at'] = null;
        }

        $this->forceFill([
            'status' => self::STATUS_SENT,
            'failure_reason' => null,
            'payload_snapshot' => $snapshot === [] ? null : $snapshot,
        ])->save();
    }

    public function recordTransportFailure(string $reason): void
    {
        $snapshot = $this->payload_snapshot ?? [];

        if (isset($snapshot['retry']) && is_array($snapshot['retry'])) {
            $snapshot['retry']['in_progress'] = false;
            $snapshot['retry']['started_at'] = null;
        }

        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'The mail transport rejected the message.';
        }

        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 2000),
            'payload_snapshot' => $snapshot === [] ? null : $snapshot,
        ])->save();
    }

    /**
     * Infer email type from subject or mail class.
     */
    public static function inferEmailType(?string $subject, ?string $mailClass = null): string
    {
        if ($mailClass) {
            $shortName = class_basename($mailClass);
            $classMap = [
                'PsmProductSubmitted' => self::TYPE_PSM_PRODUCT_SUBMISSION,
            ];

            return $classMap[$shortName] ?? $shortName;
        }

        if (! $subject) {
            return 'unknown';
        }

        $subjectLower = strtolower($subject);
        $typeMap = [
            self::TYPE_PSM_PRODUCT_SUBMISSION => ['psm product submitted', 'new psm product'],
            'verification' => ['verification', 'verify', 'confirm your email'],
            'forgot password' => ['forgot', 'password reset', 'reset password'],
            'rental request' => ['rental', 'quote request', 'rental request'],
            'job offer' => ['job offer', 'offer received'],
            'subscription' => ['subscription', 'canceled', 'cancelled'],
            'registration' => ['registration', 'welcome', 'new registration'],
            'support' => ['support', 'contact sales', 'support request'],
            'product' => ['new product', 'product added'],
        ];

        foreach ($typeMap as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($subjectLower, $keyword)) {
                    return $type;
                }
            }
        }

        return 'other';
    }
}
