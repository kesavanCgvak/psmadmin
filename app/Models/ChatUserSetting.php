<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatUserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'browser_notifications_enabled',
        'email_notifications_enabled',
        'sms_notifications_enabled',
        'sms_consented_at',
    ];

    protected function casts(): array
    {
        return [
            'browser_notifications_enabled' => 'boolean',
            'email_notifications_enabled' => 'boolean',
            'sms_notifications_enabled' => 'boolean',
            'sms_consented_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
