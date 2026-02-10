<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRating extends Model
{
    protected $fillable = [
        'rental_job_id',
        'supply_job_id',
        'rating',
        'comment',
        'rated_at',
        'skipped_at',
    ];

    protected $casts = [
        'rated_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];

    public function rentalJob(): BelongsTo
    {
        return $this->belongsTo(RentalJob::class);
    }

    public function supplyJob(): BelongsTo
    {
        return $this->belongsTo(SupplyJob::class, 'supply_job_id');
    }

    public function replies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JobRatingReply::class);
    }

    /**
     * Get the reply for this rating's supply job (one reply per supply job).
     */
    public function replyForSupplyJob(int $supplyJobId): ?JobRatingReply
    {
        return $this->replies()->where('supply_job_id', $supplyJobId)->first();
    }
}
