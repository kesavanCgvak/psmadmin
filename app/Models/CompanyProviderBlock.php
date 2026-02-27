<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyProviderBlock extends Model
{
    protected $fillable = [
        'company_id',
        'provider_id',
        'blocked_by_user_id',
        'blocked_at',
    ];

    public $timestamps = false;
}

