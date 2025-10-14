<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Country extends Model
{
    use HasFactory;

    protected $fillable = ['region_id', 'name', 'iso_code', 'numeric_code', 'phone_code'];

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
}
