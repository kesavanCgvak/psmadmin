<?php
namespace App\Models;

use App\Support\ProductNormalizer;
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
        'normalized_model',
        'normalized_full_name',
    ];

    /**
     * Boot the model and auto-normalize on save
     */
    protected static function booted()
    {
        static::saving(function (Product $product) {
            // Normalize model code
            $product->normalized_model = ProductNormalizer::normalizeCode($product->model);
            
            // Get brand name for full name normalization
            $brandName = null;
            if ($product->brand_id) {
                // Load brand if not already loaded
                if (!$product->relationLoaded('brand') && $product->brand_id) {
                    $product->load('brand');
                }
                $brandName = $product->brand->name ?? null;
            }
            
            // Normalize full name (brand + model)
            $product->normalized_full_name = ProductNormalizer::normalizeFullName($brandName, $product->model);
        });
    }

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

