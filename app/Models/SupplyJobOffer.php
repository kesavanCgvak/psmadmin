<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplyJobOffer extends Model
{
    protected $table = 'supply_job_offers';

    protected $fillable = [
        'rental_job_id',
        'version',
        'total_price',
        'status',
    ];

    public function rentalJob()
    {
        return $this->belongsTo(RentalJob::class, 'rental_job_id');
    }
}

