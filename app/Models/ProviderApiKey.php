<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderApiKey extends Model
{
    protected $fillable = [
        'provider_user_id',
        'name',
        'key_prefix',
        'key_hash',
        'is_active',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'key_hash',
    ];

    public function providerUser()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }
}

