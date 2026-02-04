<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRatingReply extends Model
{
    protected $fillable = [
        'supply_job_id',
        'job_rating_id',
        'reply',
        'replied_at',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
    ];

    public function supplyJob(): BelongsTo
    {
        return $this->belongsTo(SupplyJob::class, 'supply_job_id');
    }

    public function jobRating(): BelongsTo
    {
        return $this->belongsTo(JobRating::class);
    }
}
