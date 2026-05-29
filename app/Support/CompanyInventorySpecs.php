<?php

namespace App\Support;

use App\Models\Equipment;
use App\Models\Product;
use App\Models\RentmanEquipment;
use Illuminate\Database\Eloquent\Model;

final class CompanyInventorySpecs
{
    /** @var list<string> */
    public const FIELDS = [
        'height',
        'width',
        'length',
        'weight',
        'linear_unit_id',
        'weight_unit_id',
        'country_of_origin',
        'hsn_code',
    ];

    /**
     * Copy physical/detail attributes from inventory_master for company_inventory.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromProduct(Product $product): array
    {
        return [
            'height' => $product->height,
            'width' => $product->width,
            'length' => $product->length,
            'weight' => $product->weight,
            'linear_unit_id' => $product->linear_unit_id,
            'weight_unit_id' => $product->weight_unit_id,
            'country_of_origin' => $product->country_of_origin,
            'hsn_code' => $product->hsn_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    public static function attributesFromFlexDetails(
        array $details,
        ?int $linearUnitId = null,
        ?int $weightUnitId = null
    ): array {
        return [
            'height' => $details['height'] ?? null,
            'width' => $details['width'] ?? null,
            'length' => $details['modelLength'] ?? null,
            'weight' => $details['weight'] ?? null,
            'linear_unit_id' => $linearUnitId,
            'weight_unit_id' => $weightUnitId,
            'country_of_origin' => null,
            'hsn_code' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributesFromRentmanRow(
        RentmanEquipment $row,
        ?int $linearUnitId = null,
        ?int $weightUnitId = null
    ): array {
        return [
            'height' => $row->height,
            'width' => $row->width,
            'length' => $row->length,
            'weight' => $row->weight,
            'linear_unit_id' => $linearUnitId,
            'weight_unit_id' => $weightUnitId,
            'country_of_origin' => $row->country_of_origin,
            'hsn_code' => null,
        ];
    }

    /**
     * Merge integration values over catalog (inventory_master) values when present.
     *
     * @return array<string, mixed>
     */
    public static function mergeWithProduct(Product $product, array $integrationAttributes): array
    {
        $product->refresh();
        $merged = self::attributesFromProduct($product);

        foreach ($integrationAttributes as $field => $value) {
            if ($value !== null && $value !== '') {
                $merged[$field] = $value;
            }
        }

        return $merged;
    }

    /**
     * Build an update patch that only fills empty company_inventory spec fields.
     *
     * @return array<string, mixed>
     */
    public static function patchFillEmpty(Equipment $equipment, array $incoming): array
    {
        $patch = [];

        foreach (self::FIELDS as $field) {
            $current = $equipment->{$field};
            $new = $incoming[$field] ?? null;

            if (($current === null || $current === '') && $new !== null && $new !== '') {
                $patch[$field] = $new;
            }
        }

        return $patch;
    }

    public static function productSpecsForJson(Product $product): array
    {
        $product->loadMissing(['linearUnit:id,code,name', 'weightUnit:id,code,name']);

        return array_merge(self::attributesFromProduct($product), [
            'linear_unit_code' => $product->linearUnit?->code,
            'weight_unit_code' => $product->weightUnit?->code,
            'dimensions_display' => self::formatDimensions($product),
            'weight_display' => self::formatWeight($product),
        ]);
    }

    /**
     * Physical/detail attributes for company_inventory API responses.
     *
     * @return array<string, mixed>
     */
    public static function equipmentSpecsForJson(Equipment $equipment): array
    {
        $equipment->loadMissing(['linearUnit:id,code,name', 'weightUnit:id,code,name']);

        return [
            'height' => $equipment->height,
            'width' => $equipment->width,
            'length' => $equipment->length,
            'weight' => $equipment->weight,
            'linear_unit_id' => $equipment->linear_unit_id,
            'weight_unit_id' => $equipment->weight_unit_id,
            'linear_unit' => $equipment->linearUnit ? [
                'id' => $equipment->linearUnit->id,
                'code' => $equipment->linearUnit->code,
                'name' => $equipment->linearUnit->name,
            ] : null,
            'weight_unit' => $equipment->weightUnit ? [
                'id' => $equipment->weightUnit->id,
                'code' => $equipment->weightUnit->code,
                'name' => $equipment->weightUnit->name,
            ] : null,
            'linear_unit_code' => $equipment->linearUnit?->code,
            'weight_unit_code' => $equipment->weightUnit?->code,
            'country_of_origin' => $equipment->country_of_origin,
            'hsn_code' => $equipment->hsn_code,
            'dimensions_display' => self::formatDimensions($equipment),
            'weight_display' => self::formatWeight($equipment),
        ];
    }

    public static function formatDimensions(Model $model): ?string
    {
        if ($model->height === null && $model->width === null && $model->length === null) {
            return null;
        }

        $height = self::formatNumber($model->height) ?? '—';
        $width = self::formatNumber($model->width) ?? '—';
        $length = self::formatNumber($model->length) ?? '—';
        $formatted = "{$height} x {$width} x {$length}";

        $unitCode = self::resolveLinearUnitCode($model);
        if ($unitCode !== null) {
            $formatted .= ' ' . $unitCode;
        }

        return $formatted;
    }

    public static function formatWeight(Model $model): ?string
    {
        if ($model->weight === null) {
            return null;
        }

        $formatted = self::formatNumber($model->weight);
        $unitCode = self::resolveWeightUnitCode($model);

        return $unitCode !== null ? $formatted . ' ' . $unitCode : $formatted;
    }

    private static function resolveLinearUnitCode(Model $model): ?string
    {
        if ($model->relationLoaded('linearUnit') && $model->linearUnit) {
            return $model->linearUnit->code;
        }

        if ($model->linear_unit_id && method_exists($model, 'linearUnit')) {
            return $model->linearUnit()->value('code');
        }

        return null;
    }

    private static function resolveWeightUnitCode(Model $model): ?string
    {
        if ($model->relationLoaded('weightUnit') && $model->weightUnit) {
            return $model->weightUnit->code;
        }

        if ($model->weight_unit_id && method_exists($model, 'weightUnit')) {
            return $model->weightUnit()->value('code');
        }

        return null;
    }

    private static function formatNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
