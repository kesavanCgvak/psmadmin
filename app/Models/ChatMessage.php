<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use SoftDeletes;

    public const TYPE_TEXT = 'text';

    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'sender_company_id',
        'message',
        'message_type',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'sender_company_id');
    }
}
