<?php

namespace App\Support;

use App\Models\InventoryMasterAiSpec;
use App\Models\Product;
use App\Support\CompanyInventorySpecs;

final class InventoryAiSpecPresenter
{
    public static function formatSpecsForProduct(?Product $product): array
    {
        if (!$product) {
            return self::emptySpecRow();
        }

        $product->loadMissing(['linearUnit:id,code,name', 'weightUnit:id,code,name']);

        return [
            'height' => $product->height,
            'width' => $product->width,
            'length' => $product->length,
            'weight' => $product->weight,
            'linear_unit_id' => $product->linear_unit_id,
            'weight_unit_id' => $product->weight_unit_id,
            'linear_unit' => $product->linearUnit?->code,
            'weight_unit' => $product->weightUnit?->code,
            'dimensions_display' => CompanyInventorySpecs::formatDimensions($product),
            'weight_display' => CompanyInventorySpecs::formatWeight($product),
        ];
    }

    public static function formatSpecsForStaging(InventoryMasterAiSpec $spec): array
    {
        $spec->loadMissing(['linearUnit:id,code,name', 'weightUnit:id,code,name']);

        return [
            'height' => $spec->height,
            'width' => $spec->width,
            'length' => $spec->length,
            'weight' => $spec->weight,
            'linear_unit_id' => $spec->linear_unit_id,
            'weight_unit_id' => $spec->weight_unit_id,
            'linear_unit' => $spec->linearUnit?->code,
            'weight_unit' => $spec->weightUnit?->code,
            'dimensions_display' => self::formatDimensionsFromValues($spec),
            'weight_display' => self::formatWeightFromValues($spec),
            'source_url' => $spec->source_url,
        ];
    }

    public static function reviewerDisplayName(?\App\Models\User $user): string
    {
        if (!$user) {
            return '—';
        }

        $user->loadMissing('profile:id,user_id,full_name,first_name,last_name');

        $name = trim((string) ($user->profile?->full_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $user->username ?: $user->email ?: ('User #' . $user->id);
    }

    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            InventoryMasterAiSpec::STATUS_PENDING => 'warning',
            InventoryMasterAiSpec::STATUS_APPROVED => 'success',
            InventoryMasterAiSpec::STATUS_REJECTED => 'danger',
            InventoryMasterAiSpec::STATUS_INSUFFICIENT_INFORMATION => 'secondary',
            default => 'light',
        };
    }

    private static function emptySpecRow(): array
    {
        return [
            'height' => null,
            'width' => null,
            'length' => null,
            'weight' => null,
            'linear_unit_id' => null,
            'weight_unit_id' => null,
            'linear_unit' => null,
            'weight_unit' => null,
            'dimensions_display' => null,
            'weight_display' => null,
        ];
    }

    private static function formatDimensionsFromValues(InventoryMasterAiSpec $spec): ?string
    {
        if ($spec->height === null && $spec->width === null && $spec->length === null) {
            return null;
        }

        $length = $spec->length ?? '—';
        $width = $spec->width ?? '—';
        $height = $spec->height ?? '—';
        $formatted = "{$length} x {$width} x {$height}";

        if ($spec->linearUnit?->code) {
            $formatted .= ' ' . $spec->linearUnit->code;
        }

        return $formatted;
    }

    private static function formatWeightFromValues(InventoryMasterAiSpec $spec): ?string
    {
        if ($spec->weight === null) {
            return null;
        }

        $formatted = (string) $spec->weight;

        if ($spec->weightUnit?->code) {
            $formatted .= ' ' . $spec->weightUnit->code;
        }

        return $formatted;
    }
}
