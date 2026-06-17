<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMasterAiSpec extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_INSUFFICIENT_INFORMATION = 'insufficient_information';

    protected $table = 'inventory_master_ai_specs';

    protected $fillable = [
        'inventory_master_id',
        'height',
        'width',
        'length',
        'weight',
        'linear_unit_id',
        'weight_unit_id',
        'confidence_score',
        'source_url',
        'ai_response',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'weight' => 'decimal:2',
        'confidence_score' => 'integer',
        'ai_response' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'inventory_master_id');
    }

    public function linearUnit(): BelongsTo
    {
        return $this->belongsTo(LinearUnit::class);
    }

    public function weightUnit(): BelongsTo
    {
        return $this->belongsTo(WeightUnit::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
