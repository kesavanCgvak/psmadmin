<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderApiKey extends Model
{
    public const SCOPE_PRODUCTS = 'products';

    public const SCOPE_CHAT = 'chat';

    public const SCOPES = [
        self::SCOPE_PRODUCTS,
        self::SCOPE_CHAT,
    ];

    protected $fillable = [
        'provider_user_id',
        'name',
        'key_prefix',
        'key_hash',
        'encrypted_key',
        'is_active',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'scopes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'scopes' => 'array',
    ];

    protected $hidden = [
        'key_hash',
        'encrypted_key',
    ];

    public function providerUser()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * Legacy keys (null scopes) grant product access.
     * Partner chat accepts those keys, keys that include products, and keys that include chat.
     *
     * @return list<string>
     */
    public function grantedScopes(): array
    {
        if ($this->scopes === null) {
            return [self::SCOPE_PRODUCTS];
        }

        return array_values(array_filter(
            $this->scopes,
            fn ($scope) => is_string($scope) && $scope !== ''
        ));
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->grantedScopes(), true);
    }
}
