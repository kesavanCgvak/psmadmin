<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyReferral extends Model
{
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_CANCELLED = 'cancelled';
    // Future statuses (not used yet): qualified, rewarded

    protected $fillable = [
        'referral_link_id',
        'referrer_company_id',
        'referred_company_id',
        'referrer_user_id',
        'status',
    ];

    public function referralLink(): BelongsTo
    {
        return $this->belongsTo(ReferralLink::class);
    }

    public function referrerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'referrer_company_id');
    }

    public function referredCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'referred_company_id');
    }

    public function referrerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function isRegistered(): bool
    {
        return $this->status === self::STATUS_REGISTERED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_REGISTERED => 'Registered',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst((string) $this->status),
        };
    }
}
