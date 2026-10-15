<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * Product edit and multi-edit brand dropdowns read the cached brands_list.
     * Clear it after a brand write commits so a new brand is available immediately.
     */
    protected static function booted(): void
    {
        $forgetBrandOptions = function () {
            DB::afterCommit(function () {
                Cache::forget('brands_list');
            });
        };

        static::saved($forgetBrandOptions);
        static::deleted($forgetBrandOptions);
    }

    /**
     * A brand has many products.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
