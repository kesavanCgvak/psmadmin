<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Country extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'region_id'];

    /**
     * A country belongs to a region.
     */
    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * A country has many cities.
     */
    public function cities()
    {
        return $this->hasMany(City::class);
    }
}
