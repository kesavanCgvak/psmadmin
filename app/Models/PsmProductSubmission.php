<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PsmProductSubmission extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE = 'user_submission';

    protected $fillable = [
        'submitted_by_user_id',
        'company_id',
        'name',
        'description',
        'psm_code',
        'brand_id',
        'category_id',
        'sub_category_id',
        'webpage_url',
        'replacement_price',
        'height',
        'width',
        'length',
        'weight',
        'linear_unit_id',
        'weight_unit_id',
        'country_of_origin',
        'iso_code_2',
        'iso_code_3',
        'hsn_code',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'processed_at',
        'inventory_master_id',
    ];

    protected $casts = [
        'replacement_price' => 'decimal:2',
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'weight' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class);
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

    public function inventoryMaster(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'inventory_master_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PsmProductSubmissionImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return static::withTrashed()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'warning',
        };
    }
}
