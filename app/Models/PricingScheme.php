<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingScheme extends Model
{
    protected $fillable = [
        'code',        // The pricing scheme code (e.g., DAY, WEEK, MONTH)
        'name',        // The scheme name (e.g., Daily, Weekly, Monthly)
        'description', // Optional description
    ];

    /**
     * A pricing scheme has many companies.
     */
    public function companies()
    {
        return $this->hasMany(Company::class);
    }
}
