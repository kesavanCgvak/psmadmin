<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\NormalizesName;

class Country extends Model
{
    use HasFactory, NormalizesName;

    protected $fillable = ['region_id', 'name', 'normalized_name', 'iso_code', 'numeric_code', 'phone_code'];

    /**
     * A country belongs to a region.
     */
    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * A country has many states/provinces.
     */
    public function states()
    {
        return $this->hasMany(StateProvince::class);
    }

    /**
     * Alias for states relationship
     */
    public function statesProvinces()
    {
        return $this->hasMany(StateProvince::class);
    }

    /**
     * A country has many cities.
     */
    public function cities()
    {
        return $this->hasMany(City::class);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($country) {
            if ($country->isDirty('name')) {
                $country->normalized_name = self::normalizeName($country->name);
            }
        });
    }

    /**
     * Check if a country with the same normalized name already exists under the same region.
     * 
     * @param string $name
     * @param int $regionId
     * @param int|null $excludeId Exclude this ID from the check (for updates)
     * @return bool
     */
    public static function isDuplicate(string $name, int $regionId, ?int $excludeId = null): bool
    {
        $normalizedName = self::normalizeName($name);
        
        $query = self::where('region_id', $regionId)
            ->where('normalized_name', $normalizedName);
        
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }
}
