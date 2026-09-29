<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_RESTORED = 'restored';

    public const ENTITY_COMPANY_INVENTORY = 'company_inventory';

    public const ENTITY_COMPANY_INTEGRATION = 'company_integrations';

    protected $fillable = [
        'company_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entityLabel(): string
    {
        return match ($this->entity_type) {
            self::ENTITY_COMPANY_INVENTORY => 'Company inventory',
            self::ENTITY_COMPANY_INTEGRATION => 'Company integration',
            default => str_replace('_', ' ', (string) $this->entity_type),
        };
    }
};
