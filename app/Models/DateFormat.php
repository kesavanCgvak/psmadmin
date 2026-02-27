<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DateFormat extends Model
{
    protected $fillable = [
        'format',      // The date format (e.g., MM/DD/YYYY)
        'name',        // The format name (e.g., US Format)
        'description', // Optional description
    ];

    /**
     * A date format has many companies.
     */
    public function companies()
    {
        return $this->hasMany(Company::class);
    }
}
