<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * Product edit and multi-edit category dropdowns read the cached categories_list.
     * Clear it after a category write commits so a rename is available immediately.
     */
    protected static function booted(): void
    {
        $forgetCategoryOptions = function () {
            DB::afterCommit(function () {
                Cache::forget('categories_list');
            });
        };

        static::saved($forgetCategoryOptions);
        static::deleted($forgetCategoryOptions);
    }

    public function subCategories(): HasMany
    {
        return $this->hasMany(SubCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}

