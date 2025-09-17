<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\RentalJobProduct;
use App\Models\Product;
use App\Models\SupplyJob;

class SupplyJobProduct extends Model
{
    use HasFactory;

    protected $fillable = ['supply_job_id', 'product_id', 'offered_quantity', 'price_per_unit'];

    public function supplyJob()
    {
        return $this->belongsTo(SupplyJob::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

}

