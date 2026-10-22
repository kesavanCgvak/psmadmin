<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportSession extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const REASON_ADMIN_ENDED = 'administrator_ended';

    public const REASON_SUPPORT_ENDED = 'support_ended';

    public const REASON_SUPERSEDED = 'superseded';

    public const REASON_EXPIRED = 'expired';

    public const REASON_REVOKED = 'revoked';

    public const CLAIM_USE = 'token_use';

    public const TOKEN_USE = 'support';

    public const CLAIM_ID = 'sid';

    public const CLAIM_ADMIN = 'aid';

    protected $fillable = [
        'admin_user_id',
        'assumed_user_id',
        'status',
        'jti',
        'started_at',
        'expires_at',
        'ended_at',
        'termination_reason',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function assumedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assumed_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
