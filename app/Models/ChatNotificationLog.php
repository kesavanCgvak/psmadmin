<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatNotificationLog extends Model
{
    public const CHANNEL_BROWSER = 'browser';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_THROTTLED = 'throttled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'message_id',
        'conversation_id',
        'channel',
        'status',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SENT,
            self::STATUS_SKIPPED,
            self::STATUS_THROTTLED,
        ], true);
    }
}
