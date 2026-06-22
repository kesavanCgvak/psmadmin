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

        return self::resolveLinearUnitIdByCodeOrName($name);
    }

    public static function resolveWeightUnitIdFromFlexName(?string $name): ?int
    {
        if (!$name) {
            return null;
        }

        return self::resolveWeightUnitIdByCodeOrName($name);
    }

    public static function resolveRentmanLinearUnitId(): ?int
    {
        $configuredId = config('services.rentman.default_linear_unit_id');
        if (is_numeric($configuredId)) {
            return self::resolveConfiguredLinearUnitId((int) $configuredId);
        }

        $configuredUnit = trim((string) config('services.rentman.default_linear_unit', 'inches'));
        if ($configuredUnit !== '') {
            $unitId = self::resolveLinearUnitIdByCodeOrName($configuredUnit);
            if ($unitId !== null) {
                return $unitId;
            }

            throw new \RuntimeException(
                'Invalid Rentman configuration: RENTMAN_DEFAULT_LINEAR_UNIT (' . $configuredUnit . ') does not match any linear_units code or name.'
            );
        }

        return self::resolveLinearUnitIdByCodeOrName('inch');
    }

    public static function resolveRentmanWeightUnitId(): ?int
    {
        $configuredId = config('services.rentman.default_weight_unit_id');
        if (is_numeric($configuredId)) {
            return self::resolveConfiguredWeightUnitId((int) $configuredId);
        }

        $configuredUnit = trim((string) config('services.rentman.default_weight_unit', 'lbs'));
        if ($configuredUnit !== '') {
            $unitId = self::resolveWeightUnitIdByCodeOrName($configuredUnit);
            if ($unitId !== null) {
                return $unitId;
            }

            throw new \RuntimeException(
                'Invalid Rentman configuration: RENTMAN_DEFAULT_WEIGHT_UNIT (' . $configuredUnit . ') does not match any weight_units code or name.'
            );
        }

        return self::resolveWeightUnitIdByCodeOrName('pound');
    }

    private static function resolveConfiguredLinearUnitId(int $configuredId): int
    {
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

    private static function resolveConfiguredWeightUnitId(int $configuredId): int
    {
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

    public static function resolveLinearUnitIdByCodeOrName(string $value): ?int
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $canonical = self::LINEAR_UNIT_ALIASES[$normalized] ?? $normalized;

        $unit = LinearUnit::whereRaw('LOWER(code) = ?', [$normalized])->first()
            ?: LinearUnit::whereRaw('LOWER(code) = ?', [$canonical])->first()
            ?: LinearUnit::whereRaw('LOWER(name) = ?', [$canonical])->first()
            ?: LinearUnit::whereRaw('LOWER(name) = ?', [$normalized])->first();

        return $unit?->id;
    }

    public static function resolveWeightUnitIdByCodeOrName(string $value): ?int
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $canonical = self::WEIGHT_UNIT_ALIASES[$normalized] ?? $normalized;

        $unit = WeightUnit::whereRaw('LOWER(code) = ?', [$normalized])->first()
            ?: WeightUnit::whereRaw('LOWER(code) = ?', [$canonical])->first()
            ?: WeightUnit::whereRaw('LOWER(name) = ?', [$canonical])->first()
            ?: WeightUnit::whereRaw('LOWER(name) = ?', [$normalized])->first();

        return $unit?->id;
    }
}
