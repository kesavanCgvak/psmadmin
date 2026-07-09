<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMasterAiRejection extends Model
{
    public const CATEGORY_VALIDATION = 'validation_failure';

    public const CATEGORY_INSUFFICIENT = 'insufficient_information';

    public const CATEGORY_PROVIDER = 'provider_failure';

    public const CATEGORY_MANUAL = 'manual_rejection';

    protected $table = 'inventory_master_ai_rejections';

    protected $fillable = [
        'inventory_master_id',
        'product_name',
        'rejection_reason',
        'rejection_category',
        'rejected_at',
        'batch_run_id',
        'spec_id',
    ];

    protected $casts = [
        'rejected_at' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public static function categoryLabels(): array
    {
        return [
            self::CATEGORY_VALIDATION => 'Validation failure',
            self::CATEGORY_INSUFFICIENT => 'Insufficient information',
            self::CATEGORY_PROVIDER => 'API / provider failure',
            self::CATEGORY_MANUAL => 'Manual rejection',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'inventory_master_id');
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(InventoryMasterAiSpec::class, 'spec_id');
    }
}
