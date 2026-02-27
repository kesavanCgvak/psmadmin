<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\NormalizesName;

class City extends Model
{
    use HasFactory, NormalizesName;

    protected $fillable = ['state_id', 'country_id', 'name', 'normalized_name', 'latitude', 'longitude'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function state()
    {
        return $this->belongsTo(StateProvince::class, 'state_id');
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($city) {
            if ($city->isDirty('name')) {
                $city->normalized_name = self::normalizeNameStatic($city->name);
            }
        });
    }

    /**
     * Check if a city with the same normalized name already exists under the same state (or country if no state).
     * 
     * @param string $name
     * @param int $countryId
     * @param int|null $stateId
     * @param int|null $excludeId Exclude this ID from the check (for updates)
     * @return bool
     */
    public static function isDuplicate(string $name, int $countryId, ?int $stateId = null, ?int $excludeId = null): bool
    {
        $normalizedName = self::normalizeNameStatic($name);
        
        $query = self::where('country_id', $countryId)
            ->where('normalized_name', $normalizedName);
        
        // If state_id is provided, check within that state; otherwise check cities without state in that country
        if ($stateId !== null) {
            $query->where('state_id', $stateId);
        } else {
            $query->whereNull('state_id');
        }
        
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }
}
