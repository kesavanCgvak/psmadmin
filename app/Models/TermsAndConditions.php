<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TermsAndConditions extends Model
{
    protected $fillable = [
        'description',
    ];

    /**
     * Get the current terms and conditions (always returns the first/latest record)
     */
    public static function getCurrent()
    {
        return self::orderBy('updated_at', 'desc')->first();
    }
}
