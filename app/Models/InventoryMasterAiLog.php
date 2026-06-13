<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMasterAiLog extends Model
{
    public const UPDATED_BY_AI = 'AI';

    public const UPDATED_BY_MANUAL = 'Manual';

    public $timestamps = false;

    protected $table = 'inventory_master_ai_logs';

    protected $fillable = [
        'inventory_master_id',
        'field_name',
        'old_value',
        'new_value',
        'confidence_score',
        'source_url',
        'updated_by',
        'created_at',
    ];

    protected $casts = [
        'confidence_score' => 'integer',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'inventory_master_id');
    }
}
