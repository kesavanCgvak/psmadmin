<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyIntegration extends Model
{
    protected $fillable = [
        'company_id',
        'integration_type',
        'api_base_url',
        'api_key',
        'client_id',
        'client_secret',
        'access_token',
        'refresh_token',
        'token_expires_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'client_secret' => 'encrypted',
        'api_key' => 'encrypted',
    ];

    protected $hidden = [
        'client_secret',
        'api_key',
    ];

    /**
     * Get the company that owns the integration.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Check if the integration has valid credentials.
     * Flex uses api_key; others use client_id + client_secret.
     */
    public function isConnected(): bool
    {
        if ($this->integration_type === 'flex') {
            return !empty($this->api_key) && !empty($this->api_base_url);
        }
        return !empty($this->client_id) && !empty($this->client_secret);
    }
}
