<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'account_type',
        'stripe_subscription_id',
        'stripe_customer_id',
        'stripe_price_id',
        'stripe_status',
        'plan_name',
        'plan_type',
        'amount',
        'currency',
        'interval',
        'trial_ends_at',
        'ends_at',
        'current_period_start',
        'current_period_end',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        // Active only if status is active or trialing
        // NOT active if past_due or unpaid
        return in_array($this->stripe_status, ['active', 'trialing']);
    }

    public function isOnTrial(): bool
    {
        return $this->stripe_status === 'trialing' || 
               ($this->trial_ends_at && $this->trial_ends_at->isFuture());
    }

    public function isCanceled(): bool
    {
        return in_array($this->stripe_status, ['canceled', 'unpaid']);
    }

    public function isPaymentFailed(): bool
    {
        return in_array($this->stripe_status, ['past_due', 'unpaid']);
    }

    public function isPastDue(): bool
    {
        return $this->stripe_status === 'past_due';
    }

    public function isUnpaid(): bool
    {
        return $this->stripe_status === 'unpaid';
    }
}


