<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatUserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'browser_notifications_enabled',
    ];

    protected function casts(): array
    {
        return [
            'browser_notifications_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
