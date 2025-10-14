<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StateProvince extends Model
{
    // use SoftDeletes;
     protected $table = 'states_provinces';

    protected $fillable = ['country_id', 'name', 'code', 'type'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
    public function cities()
    {
        return $this->hasMany(City::class, 'state_id');
    }
}
