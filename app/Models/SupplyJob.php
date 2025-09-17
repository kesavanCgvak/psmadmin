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
        'unpacking_date'
    ];

    public function rentalJob()
    {
        return $this->belongsTo(RentalJob::class);
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
        return $this->hasMany(RentalJobOffer::class);
    }

    public function comments()
    {
        return $this->hasMany(RentalJobComment::class);
    }

}

