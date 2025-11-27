<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'sub_category_id',
        'brand_id',
        'model',
        'psm_code',
        'is_verified',
        'webpage_url',
    ];

    /**
     * A product belongs to a category.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * A product belongs to a sub-category.
     */
    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class);
    }

    /**
     * A product belongs to a brand.
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * A product has many equipments.
     */
    // public function equipments()
    // {
    //     return $this->hasMany(Equipment::class);
    // }

    public function equipments()
    {
        return $this->hasMany(Equipment::class, 'product_id', 'id');
    }


    public function rentalJobProducts()
    {
        return $this->hasMany(RentalJobProduct::class);
    }

    public function supplyJobProducts()
    {
        return $this->hasMany(SupplyJobProduct::class);
    }

    public function getEquipment()
    {
        return $this->belongsTo('App\Models\Equipment', 'id', 'product_id');
    }

    public function getSoftwareCodeAttribute()
    {
        return $this->equipments->first()->software_code ?? null;
    }

}

