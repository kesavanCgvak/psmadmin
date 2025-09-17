<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\SupplyJob;
use App\Models\RentalJobProduct;
use App\Models\RentalJobComment;
use App\Models\User;

class RentalJobOffer extends Model
{
    use HasFactory;

    protected $fillable = ['supply_job_id', 'version', 'total_price', 'status'];

    public function supplyJob()
    {
        return $this->belongsTo(SupplyJob::class);
    }
}
