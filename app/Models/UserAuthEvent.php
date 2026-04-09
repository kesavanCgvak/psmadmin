<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAuthEvent extends Model
{
    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_FAILED_LOGIN = 'failed_login';

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_API = 'api';

    protected $fillable = [
        'user_id',
        'event_type',
        'channel',
        'ip_address',
        'user_agent',
        'identifier',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
