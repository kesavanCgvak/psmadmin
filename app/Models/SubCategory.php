<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubCategory extends Model
{
    use HasFactory;

    protected $table = 'sub_categories';

    protected $fillable = ['name', 'category_id'];

    /**
     * Product screens forget subcategories_list after product changes.
     * Forget it after a subcategory write commits as well. The dropdowns
     * themselves query sub_categories on each request.
     */
    protected static function booted(): void
    {
        $forgetSubCategoryOptions = function () {
            DB::afterCommit(function () {
                Cache::forget('subcategories_list');
            });
        };

        static::saved($forgetSubCategoryOptions);
        static::deleted($forgetSubCategoryOptions);
    }

    /**
     * A sub-category belongs to a category.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * A sub-category has many products.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
