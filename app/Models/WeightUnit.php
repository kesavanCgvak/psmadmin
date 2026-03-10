<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightUnit extends Model
{
    protected $fillable = [
        'name',
        'code',
        'system',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
