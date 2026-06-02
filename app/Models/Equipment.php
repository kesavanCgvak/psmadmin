<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Equipment extends Model
{
    use HasFactory;
    protected $table = 'company_inventory';

    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'rental_price',
        'replacement_price',
        'description',
        'software_code',
        'company_id',
        'flex_resource_id',
        'rentman_equipment_id',
        'height',
        'width',
        'length',
        'weight',
        'linear_unit_id',
        'weight_unit_id',
        'country_of_origin',
        'hsn_code',
    ];

    protected $casts = [
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // public function product()
    // {
    //     return $this->belongsTo(Product::class, 'product_id');
    // }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function images()
    {
        return $this->hasMany(EquipmentImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function linearUnit()
    {
        return $this->belongsTo(LinearUnit::class);
    }

    public function weightUnit()
    {
        return $this->belongsTo(WeightUnit::class);
    }
}
