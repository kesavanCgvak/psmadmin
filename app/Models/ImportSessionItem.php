<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportSessionItem extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ANALYZED = 'analyzed';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'import_session_id',
        'excel_row_number',
        'original_description',
        'detected_model',
        'normalized_model',
        'quantity',
        'price',
        'software_code',
        'status',
        'is_skipped',
        'rejection_reason',
        'action', // 'attach' or 'create'
        'selected_product_id', // User's selection for attach action
    ];

    protected $casts = [
        'is_skipped' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ImportSession::class, 'import_session_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ImportSessionMatch::class);
    }

    public function selectedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'selected_product_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAnalyzed(): bool
    {
        return $this->status === self::STATUS_ANALYZED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isSkipped(): bool
    {
        return (bool) $this->is_skipped;
    }
}

