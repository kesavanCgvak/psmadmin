<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlexIntegrationLog extends Model
{
    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const ACTION_CHECK_INTEGRATION = 'CHECK_INTEGRATION';

    public const ACTION_FETCH_REFERRAL_SOURCE = 'FETCH_REFERRAL_SOURCE';

    public const ACTION_CREATE_CLIENT = 'CREATE_CLIENT';

    public const ACTION_CREATE_QUOTE = 'CREATE_QUOTE';

    public const ACTION_SEARCH_PRODUCT = 'SEARCH_PRODUCT';

    public const ACTION_ADD_PRODUCT_TO_QUOTE = 'ADD_PRODUCT_TO_QUOTE';

    public const ACTION_TRACK_EVENT = 'TRACK_EVENT';

    public const ACTION_PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';

    public const ACTION_API_ERROR = 'API_ERROR';

    public const ACTION_DIAGNOSTIC = 'DIAGNOSTIC';

    public const ACTION_FETCH_ELEMENT_FIELDS = 'FETCH_ELEMENT_FIELDS';

    public const ACTION_FETCH_DEFINITION_ID = 'FETCH_DEFINITION_ID';

    public const ACTION_FETCH_INVENTORY_GROUP = 'FETCH_INVENTORY_GROUP';

    public const ACTION_VALIDATE_PRODUCT = 'VALIDATE_PRODUCT';

    public const ACTION_CREATE_PRODUCT = 'CREATE_PRODUCT';

    public const ACTION_UPDATE_CUSTOM_FIELD = 'UPDATE_CUSTOM_FIELD';

    public const ACTION_PERSIST_RESOURCE_ID = 'PERSIST_RESOURCE_ID';

    public const ACTION_SET_QUOTE_ADDRESS = 'SET_QUOTE_ADDRESS';

    public const ACTION_CREATE_QUOTE_NOTE = 'CREATE_QUOTE_NOTE';

    public $timestamps = false;

    protected $table = 'flex_integration_logs';

    protected $fillable = [
        'rental_request_id',
        'provider_id',
        'action',
        'status',
        'request_url',
        'request_payload',
        'response_payload',
        'error_message',
        'flex_quote_id',
        'flex_product_id',
        'created_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (FlexIntegrationLog $log) {
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
