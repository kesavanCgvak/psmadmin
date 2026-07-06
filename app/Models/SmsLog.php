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
