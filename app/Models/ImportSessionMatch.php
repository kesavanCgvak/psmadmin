<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportSessionMatch extends Model
{
    protected $fillable = [
        'import_session_item_id',
        'product_id',
        'psm_code',
        'confidence',
        'match_type',
    ];

    protected $casts = [
        'confidence' => 'float',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ImportSessionItem::class, 'import_session_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

