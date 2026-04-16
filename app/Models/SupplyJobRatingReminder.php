<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyJobRatingReminder extends Model
{
    protected $fillable = [
        'supply_job_id',
        'days_after_completed',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function supplyJob(): BelongsTo
    {
        return $this->belongsTo(SupplyJob::class, 'supply_job_id');
    }
}
