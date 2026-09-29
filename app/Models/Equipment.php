<?php
namespace App\Models;

use App\Traits\Auditable;
use App\Traits\MaintainsActiveUniqueKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Equipment extends Model
{
    use HasFactory, SoftDeletes, Auditable, MaintainsActiveUniqueKeys {
        MaintainsActiveUniqueKeys::runSoftDelete insteadof SoftDeletes;
    }

    protected $table = 'company_inventory';

    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'rental_price',
        'replacement_price',
        'description',
        'software_code',
        'company_id',
        'flex_resource_id',
        'rentman_equipment_id',
        'height',
        'width',
        'length',
        'weight',
        'linear_unit_id',
        'weight_unit_id',
        'country_of_origin',
        'hsn_code',
    ];

    protected $casts = [
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    /**
     * Active-row uniqueness keys. Cleared when the row is soft-deleted.
     *
     * @var list<string>
     */
    protected $hidden = [
        'active_rentman_key',
        'active_flex_key',
    ];

    /**
     * @return array<string, ?string>
     */
    public function activeUniqueKeyMap(): array
    {
        return [
            'active_rentman_key' => $this->composeActiveKey($this->company_id, $this->rentman_equipment_id),
            'active_flex_key' => $this->composeActiveKey($this->company_id, $this->flex_resource_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditSupplements(array $attributes): array
    {
        $productId = $attributes['product_id'] ?? null;
        if (!$productId) {
            return [];
        }

        $product = Product::query()->select(['model', 'psm_code'])->find($productId);
        if (!$product) {
            return [];
        }

        return array_filter([
            'equipment_name' => $product->model,
            'psm_code' => $product->psm_code,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // public function product()
    // {
    //     return $this->belongsTo(Product::class, 'product_id');
    // }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function images()
    {
        return $this->hasMany(EquipmentImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function linearUnit()
    {
        return $this->belongsTo(LinearUnit::class);
    }

    public function weightUnit()
    {
        return $this->belongsTo(WeightUnit::class);
    }
}
