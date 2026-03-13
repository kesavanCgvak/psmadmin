<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenterRating extends Model
{
    protected $table = 'renter_ratings';

    protected $fillable = [
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

    public function supplyJob(): BelongsTo
    {
        return $this->belongsTo(SupplyJob::class, 'supply_job_id');
    }
}
