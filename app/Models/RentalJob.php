<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\RentalJobProduct;
use App\Models\SupplyJob;
use App\Models\RentalJobComment;
use App\Models\User;


class RentalJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'from_date',
        'to_date',
        'delivery_address',
        'offer_requirements',
        'global_message',
        'status',
        'cancelled_by',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->hasMany(RentalJobProduct::class);
    }

    public function supplyJobs()
    {
        return $this->hasMany(SupplyJob::class);
    }

    public function comments()
    {
        return $this->hasMany(RentalJobComment::class);
    }

    public function offers()
    {
        return $this->hasMany(JobOffer::class, 'rental_job_id');
    }

    public function getTotalRequestedQuantityAttribute()
    {
        return $this->products->sum('requested_quantity');
    }

    // public function supplyOffers()
    // {
    //     return $this->hasMany(SupplyJobOffer::class, 'rental_job_id');
    // }

    public function requesterCompany()
    {
        return $this->belongsTo(Company::class, 'requester_company_id');
    }

}

