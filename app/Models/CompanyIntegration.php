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
        'settings',
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
        'settings' => 'array',
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
     * Flex requires api_key + api_base_url; Rentman requires api_key.
     */
    public function isConnected(): bool
    {
        if (in_array($this->integration_type, ['flex', 'rentman'], true)) {
            if ($this->integration_type === 'flex') {
                return !empty($this->api_key) && !empty($this->api_base_url);
            }

            return !empty($this->api_key);
        }

        return !empty($this->client_id) && !empty($this->client_secret);
    }
}
