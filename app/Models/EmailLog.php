<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * Header name used to pass email log ID between MessageSending and MessageSent events.
     */
    public const LOG_ID_HEADER = 'X-Email-Log-Id';

    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }

    /**
     * Infer email type from subject or mail class.
     */
    public static function inferEmailType(?string $subject, ?string $mailClass): string
    {
        if ($mailClass) {
            $shortName = class_basename($mailClass);
            return $shortName;
        }

        if (!$subject) {
            return 'unknown';
        }

        $subjectLower = strtolower($subject);
        $typeMap = [
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
