<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Product;
use App\Models\RentalJob;
use App\Models\Company;

class RentalJobProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'rental_job_id',
        'product_id',
        'requested_quantity',
        'company_id' // NEW: Track which company is responsible for this product
    ];

    public function rentalJob()
    {
        return $this->belongsTo(RentalJob::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}

