<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = [
        'code',     // The currency code (e.g., USD)
        'name',     // The currency name (e.g., United States Dollar)
        'symbol',   // The currency symbol (e.g., $, €, ₹)
    ];

    /**
     * A currency has many companies.
     */
    public function companies()
    {
        return $this->hasMany(Company::class);
    }
}
