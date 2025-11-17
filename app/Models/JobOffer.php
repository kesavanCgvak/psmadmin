<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOffer extends Model
{
    protected $fillable = [
        'rental_job_id',
        'supply_job_id',
        'sender_company_id',
        'receiver_company_id',
        'version',
        'total_price',
        'currency_id',
        'last_offer_by',
        'status'
    ];

    public function rentalJob()
    {
        return $this->belongsTo(RentalJob::class);
    }

    // public function supplyJob()
    // {
    //     return $this->belongsTo(SupplyJob::class);
    // }

    public function supplyJob()
    {
        return $this->belongsTo(SupplyJob::class, 'supply_job_id', 'id');
    }


    public function senderCompany()
    {
        return $this->belongsTo(Company::class, 'sender_company_id');
    }

    public function receiverCompany()
    {
        return $this->belongsTo(Company::class, 'receiver_company_id');
    }
}

