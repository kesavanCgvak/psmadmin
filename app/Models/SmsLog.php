<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'provider',
        'provider_message_id',
        'status',
        'message',
        'recipient_name',
        'phone_number',
        'company_id',
        'company_name',
        'contact_person_name',
        'contact_person_mobile',
        'related_type',
        'related_id',
        'sent_by',
        'error_message',
        'provider_response',
        'attempts',
        'sent_at',
    ];

    protected $casts = [
        'provider_response' => 'array',
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    public const SENT_BY_SYSTEM = 'System';
    public const SENT_BY_USER = 'User';
    public const SENT_BY_ADMIN = 'Admin';

    public const RELATED_RENTAL_REQUEST = 'Rental Request';

    public const SKIP_OUTSIDE_WINDOW = 'Skipped: rental start date is more than 7 days away.';

    public const SKIP_PAST = 'Skipped: rental start date is in the past.';

    public const SKIP_COMPANY_MISSING = 'Skipped: supplier company is missing.';

    public const SKIP_CONTACT_MISSING = 'Skipped: supplier contact is missing.';

    public const SKIP_MOBILE_MISSING = 'Skipped: supplier mobile number is missing.';

    public const SKIP_MOBILE_INVALID = 'Skipped: supplier mobile number is invalid.';

    /** Stored on the existing failed status. The sms_logs enum has no separate permanently_failed value. */
    public const FAILURE_RECIPIENT_UNSUBSCRIBED = 'recipient_unsubscribed';

    public const TWILIO_UNSUBSCRIBED_CODE = 21610;

    public function isSkip(): bool
    {
        return $this->status === self::STATUS_FAILED
            && is_string($this->error_message)
            && str_starts_with($this->error_message, 'Skipped:');
    }

    public function isOutsideWindowSkip(): bool
    {
        return is_string($this->error_message)
            && str_starts_with($this->error_message, self::SKIP_OUTSIDE_WINDOW);
    }

    public function isRecipientUnsubscribed(): bool
    {
        return $this->status === self::STATUS_FAILED
            && is_string($this->error_message)
            && str_contains($this->error_message, self::FAILURE_RECIPIENT_UNSUBSCRIBED);
    }

    public static function unsubscribedFailureMessage(string $twilioMessage): string
    {
        $twilioMessage = trim($twilioMessage);
        if ($twilioMessage === '') {
            $twilioMessage = 'Attempt to send to unsubscribed recipient';
        }

        return 'Permanent: '.self::FAILURE_RECIPIENT_UNSUBSCRIBED
            .'. Twilio '.self::TWILIO_UNSUBSCRIBED_CODE.': '.$twilioMessage;
    }

    /**
     * All possible delivery statuses (for filter dropdowns / validation).
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * The company this SMS relates to (denormalized name is always stored too).
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
