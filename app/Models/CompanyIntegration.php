<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\MaintainsActiveUniqueKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyIntegration extends Model
{
    use SoftDeletes, Auditable, MaintainsActiveUniqueKeys {
        MaintainsActiveUniqueKeys::runSoftDelete insteadof SoftDeletes;
    }
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
        'last_fetched_at',
        'last_synced_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_fetched_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'client_secret' => 'encrypted',
        'api_key' => 'encrypted',
    ];

    protected $hidden = [
        'client_secret',
        'api_key',
        'active_integration_key',
    ];

    /**
     * @return array<string, ?string>
     */
    public function activeUniqueKeyMap(): array
    {
        return [
            'active_integration_key' => $this->composeActiveKey($this->company_id, $this->integration_type),
        ];
    }

    /**
     * Token refresh and sync timestamps are internal and are not audited.
     *
     * @return list<string>
     */
    public function auditIgnoredAttributes(): array
    {
        return [
            'last_fetched_at',
            'last_synced_at',
            'token_expires_at',
            'access_token',
            'refresh_token',
        ];
    }

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
