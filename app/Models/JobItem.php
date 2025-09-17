<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobItem extends Model
{
    protected $fillable = [
        'rental_job_id',
        'supply_job_id',
        'product_id',
        'equipment_id',
        'name',
        'quantity',
        'software_code',
    ];

    public function rentalJob(): BelongsTo
    {
        return $this->belongsTo(RentalJob::class);
    }

    public function supplyJob(): BelongsTo
    {
        return $this->belongsTo(SupplyJob::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
