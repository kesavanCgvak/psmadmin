<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\RentalJob;
use App\Models\SupplyJob;
use App\Models\User;

class RentalJobComment extends Model
{
    use HasFactory;

    protected $fillable = ['rental_job_id', 'supply_job_id', 'sender_id', 'recipient_id', 'message', 'is_private'];

    public function rentalJob()
    {
        return $this->belongsTo(RentalJob::class);
    }

    public function supplyJob()
    {
        return $this->belongsTo(SupplyJob::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}

