<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\NormalizesName;

class StateProvince extends Model
{
    // use SoftDeletes;
     protected $table = 'states_provinces';

    use NormalizesName;

    protected $fillable = ['country_id', 'name', 'normalized_name', 'code', 'type'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
    public function cities()
    {
        return $this->hasMany(City::class, 'state_id');
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($state) {
            if ($state->isDirty('name')) {
                $state->normalized_name = self::normalizeName($state->name);
            }
        });
    }

    /**
     * Check if a state/province with the same normalized name already exists under the same country.
     * 
     * @param string $name
     * @param int $countryId
     * @param int|null $excludeId Exclude this ID from the check (for updates)
     * @return bool
     */
    public static function isDuplicate(string $name, int $countryId, ?int $excludeId = null): bool
    {
        $normalizedName = self::normalizeName($name);
        
        $query = self::where('country_id', $countryId)
            ->where('normalized_name', $normalizedName);
        
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }
}
