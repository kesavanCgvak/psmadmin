<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMasterImage extends Model
{
    protected $fillable = [
        'inventory_master_id',
        'image_path',
        'is_primary',
        'sort_order',
        'source',
        'created_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'inventory_master_id');
    }
}
