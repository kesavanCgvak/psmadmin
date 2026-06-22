<?php

namespace App\Support;

use App\Models\EquipmentImage;
use App\Models\InventoryMasterImage;
use Illuminate\Support\Facades\DB;

final class InventoryImageSyncService
{
    /**
     * Copy all master images to company equipment (dedupe by image_path by default).
     */
    public static function syncMasterToEquipment(
        int $inventoryMasterId,
        int $equipmentId,
        bool $onlyMissingPaths = true
    ): int {
        $masterImages = InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($masterImages->isEmpty()) {
            return 0;
        }

        $copied = 0;

        DB::transaction(function () use ($masterImages, $equipmentId, $onlyMissingPaths, &$copied) {
            foreach ($masterImages as $master) {
                $exists = EquipmentImage::query()
                    ->where('equipment_id', $equipmentId)
                    ->where('image_path', $master->image_path)
                    ->exists();

                if ($exists) {
                    if ($onlyMissingPaths) {
                        continue;
                    }

                    continue;
                }

                if ($master->is_primary) {
                    EquipmentImage::query()
                        ->where('equipment_id', $equipmentId)
                        ->update(['is_primary' => false]);
                }

                EquipmentImage::create([
                    'equipment_id' => $equipmentId,
                    'image_path' => $master->image_path,
                    'is_primary' => (bool) $master->is_primary,
                    'sort_order' => $master->sort_order,
                ]);

                $copied++;
            }

            self::ensureEquipmentPrimary($equipmentId);
        });

        return $copied;
    }

    /**
     * Store image URLs on inventory_master (dedupe by path).
     *
     * @param  list<string>  $imageUrls
     */
    public static function storeMasterImagesFromUrls(
        int $inventoryMasterId,
        array $imageUrls,
        string $source = 'admin',
        ?int $createdBy = null
    ): int {
        $urls = self::normalizeUrls($imageUrls);
        if ($urls === []) {
            return 0;
        }

        $existingPaths = InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->pluck('image_path')
            ->all();

        $hasPrimary = InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->where('is_primary', true)
            ->exists();

        $maxSort = (int) InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->max('sort_order');

        $added = 0;
        $sort = $maxSort;

        foreach ($urls as $url) {
            if (in_array($url, $existingPaths, true)) {
                continue;
            }

            $sort++;
            $isPrimary = !$hasPrimary && $added === 0;

            InventoryMasterImage::create([
                'inventory_master_id' => $inventoryMasterId,
                'image_path' => $url,
                'is_primary' => $isPrimary,
                'sort_order' => $sort,
                'source' => $source,
                'created_by' => $createdBy,
            ]);

            $existingPaths[] = $url;
            if ($isPrimary) {
                $hasPrimary = true;
            }
            $added++;
        }

        self::ensureMasterPrimary($inventoryMasterId);

        return $added;
    }

    /**
     * Flex/Rentman/import: master first, then copy to equipment.
     *
     * @param  list<string>  $imageUrls
     */
    public static function importUrlsToMasterAndEquipment(
        int $inventoryMasterId,
        int $equipmentId,
        array $imageUrls,
        string $source,
        ?int $createdBy = null
    ): void {
        self::storeMasterImagesFromUrls($inventoryMasterId, $imageUrls, $source, $createdBy);
        self::syncMasterToEquipment($inventoryMasterId, $equipmentId, true);
    }

    public static function ensureMasterPrimary(int $inventoryMasterId): void
    {
        $hasPrimary = InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->where('is_primary', true)
            ->exists();

        if ($hasPrimary) {
            return;
        }

        $first = InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($first) {
            $first->update(['is_primary' => true]);
        }
    }

    public static function ensureEquipmentPrimary(int $equipmentId): void
    {
        $hasPrimary = EquipmentImage::query()
            ->where('equipment_id', $equipmentId)
            ->where('is_primary', true)
            ->exists();

        if ($hasPrimary) {
            return;
        }

        $first = EquipmentImage::query()
            ->where('equipment_id', $equipmentId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($first) {
            $first->update(['is_primary' => true]);
        }
    }

    /**
     * @param  list<string>  $imageUrls
     * @return list<string>
     */
    public static function normalizeUrls(array $imageUrls): array
    {
        $normalized = [];
        foreach ($imageUrls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $normalized[] = $url;
        }

        return array_values(array_unique($normalized));
    }
}
