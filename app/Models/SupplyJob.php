<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\RentalJobProduct;
use App\Models\RentalJobComment;

class SupplyJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'rental_job_id',
        'provider_id',
        'status',
        'quote_price',
        'notes',
        'packing_date',
        'delivery_date',
        'return_date',
        'unpacking_date',
        'completed_at',
        'accepted_price',
        'handshake_status',
        'cancelled_by',
        'fulfilled_quantity',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function rentalJob()
    {
        return $this->belongsTo(RentalJob::class, 'rental_job_id');
    }

    public function provider()
    {
        return $this->belongsTo(Company::class, 'provider_id');
    }

    public function providerCompany()
    {
        return $this->belongsTo(Company::class, 'provider_id');
    }

    public function products()
    {
        return $this->hasMany(SupplyJobProduct::class);
    }

    public function offers()
    {
        return $this->hasMany(JobOffer::class, 'rental_job_id');
    }

    public function supplyJobOffers()
    {
        return $this->hasMany(JobOffer::class, 'supply_job_id', 'id');
    }


    public function comments()
    {
        return $this->hasMany(RentalJobComment::class);
    }

    /** Rating for this supply job only (one per provider). */
    public function jobRating()
    {
        return $this->hasOne(JobRating::class, 'supply_job_id');
    }

    public function ratingReply()
    {
        return $this->hasOne(JobRatingReply::class, 'supply_job_id');
    }

    public function completionReminders()
    {
        return $this->hasMany(SupplyJobCompletionReminder::class, 'supply_job_id');
    }

    public function ratingReminders()
    {
        return $this->hasMany(SupplyJobRatingReminder::class, 'supply_job_id');
    }

}

