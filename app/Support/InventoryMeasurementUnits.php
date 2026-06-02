<?php

namespace App\Support;

use App\Models\LinearUnit;
use App\Models\WeightUnit;
use Illuminate\Support\Facades\Log;

final class InventoryMeasurementUnits
{
    private const LINEAR_UNIT_ALIASES = [
        'feet' => 'foot',
        'ft' => 'foot',
        'inches' => 'inch',
        'in' => 'inch',
        'meters' => 'meter',
        'm' => 'meter',
        'centimeters' => 'centimeter',
        'cm' => 'centimeter',
    ];

    private const WEIGHT_UNIT_ALIASES = [
        'pounds' => 'pound',
        'lbs' => 'pound',
        'lb' => 'pound',
        'kilograms' => 'kilogram',
        'kg' => 'kilogram',
        'grams' => 'gram',
        'g' => 'gram',
    ];

    public static function resolveLinearUnitIdFromFlexName(?string $name): ?int
    {
        if (!$name) {
            return null;
        }

        $normalized = strtolower(trim($name));
        $canonical = self::LINEAR_UNIT_ALIASES[$normalized] ?? $normalized;

        $unit = LinearUnit::whereRaw('LOWER(name) = ?', [$canonical])->first()
            ?: LinearUnit::whereRaw('LOWER(name) = ?', [$normalized])->first();

        return $unit?->id;
    }

    public static function resolveWeightUnitIdFromFlexName(?string $name): ?int
    {
        if (!$name) {
            return null;
        }

        $normalized = strtolower(trim($name));
        $canonical = self::WEIGHT_UNIT_ALIASES[$normalized] ?? $normalized;

        $unit = WeightUnit::whereRaw('LOWER(name) = ?', [$canonical])->first()
            ?: WeightUnit::whereRaw('LOWER(name) = ?', [$normalized])->first();

        return $unit?->id;
    }

    public static function resolveRentmanLinearUnitId(): ?int
    {
        $configuredId = config('services.rentman.default_linear_unit_id');
        if (is_numeric($configuredId)) {
            $configuredId = (int) $configuredId;
            if (!LinearUnit::whereKey($configuredId)->exists()) {
                Log::error('Invalid Rentman linear unit configuration', [
                    'configured_linear_unit_id' => $configuredId,
                ]);
                throw new \RuntimeException(
                    'Invalid Rentman configuration: RENTMAN_DEFAULT_LINEAR_UNIT_ID (' . $configuredId . ') does not exist in linear_units.'
                );
            }

            return $configuredId;
        }

        $unit = LinearUnit::whereRaw('LOWER(name) = ?', ['inch'])->first()
            ?: LinearUnit::whereRaw('LOWER(name) = ?', ['inches'])->first()
            ?: LinearUnit::whereRaw('LOWER(name) = ?', ['in'])->first();

        return $unit?->id;
    }

    public static function resolveRentmanWeightUnitId(): ?int
    {
        $configuredId = config('services.rentman.default_weight_unit_id');
        if (is_numeric($configuredId)) {
            $configuredId = (int) $configuredId;
            if (!WeightUnit::whereKey($configuredId)->exists()) {
                Log::error('Invalid Rentman weight unit configuration', [
                    'configured_weight_unit_id' => $configuredId,
                ]);
                throw new \RuntimeException(
                    'Invalid Rentman configuration: RENTMAN_DEFAULT_WEIGHT_UNIT_ID (' . $configuredId . ') does not exist in weight_units.'
                );
            }

            return $configuredId;
        }

        $unit = WeightUnit::whereRaw('LOWER(name) = ?', ['pound'])->first()
            ?: WeightUnit::whereRaw('LOWER(name) = ?', ['pounds'])->first()
            ?: WeightUnit::whereRaw('LOWER(name) = ?', ['lbs'])->first()
            ?: WeightUnit::whereRaw('LOWER(name) = ?', ['lb'])->first();

        return $unit?->id;
    }
}
