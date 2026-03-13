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
        'description',
        'software_code',
        'company_id',
        'flex_resource_id',
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
        return $this->hasMany(EquipmentImage::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
