<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatExternalMessage extends Model
{
    protected $fillable = [
        'company_id',
        'external_system',
        'external_message_id',
        'external_conversation_id',
        'conversation_id',
        'message_id',
        'body_hash',
        'external_created_at',
    ];

    protected function casts(): array
    {
        return [
            'external_created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
