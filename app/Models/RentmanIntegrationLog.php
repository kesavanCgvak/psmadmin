<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentmanIntegrationLog extends Model
{
    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const ACTION_CHECK_INTEGRATION = 'CHECK_INTEGRATION';

    public const ACTION_CREATE_CONTACT = 'CREATE_CONTACT';

    public const ACTION_CREATE_CONTACT_PERSON = 'CREATE_CONTACT_PERSON';

    public const ACTION_UPDATE_CONTACT = 'UPDATE_CONTACT';

    public const ACTION_SEARCH_CONTACT = 'SEARCH_CONTACT';

    public const ACTION_CREATE_PROJECT_REQUEST = 'CREATE_PROJECT_REQUEST';

    public const ACTION_UPDATE_PROJECT_REQUEST = 'UPDATE_PROJECT_REQUEST';

    public const ACTION_LINK_CONTACT = 'LINK_CONTACT';

    public const ACTION_LINK_LOCATION = 'LINK_LOCATION';

    public const ACTION_CREATE_NOTE = 'CREATE_NOTE';

    public const ACTION_SEARCH_EQUIPMENT = 'SEARCH_EQUIPMENT';

    public const ACTION_SYNC_EQUIPMENT = 'SYNC_EQUIPMENT';

    public const ACTION_CREATE_EQUIPMENT = 'CREATE_EQUIPMENT';

    public const ACTION_UPDATE_EQUIPMENT = 'UPDATE_EQUIPMENT';

    public const ACTION_VALIDATE_EQUIPMENT = 'VALIDATE_EQUIPMENT';

    public const ACTION_ADD_EQUIPMENT_TO_REQUEST = 'ADD_EQUIPMENT_TO_REQUEST';

    public const ACTION_PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';

    public const ACTION_PERSIST_EQUIPMENT_ID = 'PERSIST_EQUIPMENT_ID';

    public const ACTION_API_ERROR = 'API_ERROR';

    public const ACTION_DIAGNOSTIC = 'DIAGNOSTIC';

    public $timestamps = false;

    protected $table = 'rentman_integration_logs';

    protected $fillable = [
        'rental_request_id',
        'provider_id',
        'action',
        'status',
        'request_url',
        'request_payload',
        'response_payload',
        'error_message',
        'rentman_project_request_id',
        'rentman_equipment_id',
        'created_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (RentmanIntegrationLog $log) {
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });
    }

    public function rentalRequest(): BelongsTo
    {
        return $this->belongsTo(RentalJob::class, 'rental_request_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'provider_id');
    }
}
