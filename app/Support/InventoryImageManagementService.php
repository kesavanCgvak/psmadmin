<?php

namespace App\Support;

use App\Models\EquipmentImage;
use App\Models\InventoryMasterImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class InventoryImageManagementService
{
    public const MASTER_UPLOAD_DIR = 'images/inventory_master';

    public const EQUIPMENT_UPLOAD_DIR = 'images/equipment_image';

    public static function publicUrl(string $imagePath): string
    {
        return str_starts_with($imagePath, 'http') ? $imagePath : asset($imagePath);
    }

    public static function storeUploadedFile(UploadedFile $file, string $relativeDir): string
    {
        $destinationPath = public_path($relativeDir);
        if (!is_dir($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move($destinationPath, $filename);

        return rtrim($relativeDir, '/') . '/' . $filename;
    }

    public static function deleteLocalFileIfStored(?string $imagePath): void
    {
        if ($imagePath === null || $imagePath === '' || str_starts_with($imagePath, 'http')) {
            return;
        }

        $filePath = public_path($imagePath);
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    public static function addMasterImage(
        int $inventoryMasterId,
        ?UploadedFile $file = null,
        ?string $imagePath = null,
        ?int $createdBy = null
    ): InventoryMasterImage {
        $path = self::resolvePath($file, $imagePath, self::MASTER_UPLOAD_DIR);

        $duplicate = InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->where('image_path', $path)
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException('This image path already exists for this product.');
        }

        $hasPrimary = InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->where('is_primary', true)
            ->exists();

        $maxSort = (int) InventoryMasterImage::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->max('sort_order');

        $image = InventoryMasterImage::create([
            'inventory_master_id' => $inventoryMasterId,
            'image_path' => $path,
            'is_primary' => !$hasPrimary,
            'sort_order' => $maxSort + 1,
            'source' => 'admin',
            'created_by' => $createdBy,
        ]);

        InventoryImageSyncService::ensureMasterPrimary($inventoryMasterId);

        return $image;
    }

    public static function addEquipmentImage(
        int $equipmentId,
        ?UploadedFile $file = null,
        ?string $imagePath = null
    ): EquipmentImage {
        $path = self::resolvePath($file, $imagePath, self::EQUIPMENT_UPLOAD_DIR);

        $duplicate = EquipmentImage::query()
            ->where('equipment_id', $equipmentId)
            ->where('image_path', $path)
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException('This image path already exists for this equipment.');
        }

        $hasPrimary = EquipmentImage::query()
            ->where('equipment_id', $equipmentId)
            ->where('is_primary', true)
            ->exists();

        $maxSort = (int) EquipmentImage::query()
            ->where('equipment_id', $equipmentId)
            ->max('sort_order');

        $image = EquipmentImage::create([
            'equipment_id' => $equipmentId,
            'image_path' => $path,
            'is_primary' => !$hasPrimary,
            'sort_order' => $maxSort + 1,
        ]);

        InventoryImageSyncService::ensureEquipmentPrimary($equipmentId);

        return $image;
    }

    public static function replaceMasterImageFile(InventoryMasterImage $image, UploadedFile $file): void
    {
        self::deleteLocalFileIfStored($image->image_path);
        $path = self::storeUploadedFile($file, self::MASTER_UPLOAD_DIR);
        $image->update(['image_path' => $path]);
    }

    public static function replaceMasterImagePath(InventoryMasterImage $image, string $imagePath): void
    {
        $path = trim($imagePath);
        if ($path === '') {
            throw new \InvalidArgumentException('Image path is required.');
        }

        $duplicate = InventoryMasterImage::query()
            ->where('inventory_master_id', $image->inventory_master_id)
            ->where('image_path', $path)
            ->where('id', '!=', $image->id)
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException('This image path already exists for this product.');
        }

        $image->update(['image_path' => $path]);
    }

    public static function replaceEquipmentImageFile(EquipmentImage $image, UploadedFile $file): void
    {
        self::deleteLocalFileIfStored($image->image_path);
        $path = self::storeUploadedFile($file, self::EQUIPMENT_UPLOAD_DIR);
        $image->update(['image_path' => $path]);
    }

    public static function replaceEquipmentImagePath(EquipmentImage $image, string $imagePath): void
    {
        $path = trim($imagePath);
        if ($path === '') {
            throw new \InvalidArgumentException('Image path is required.');
        }

        $duplicate = EquipmentImage::query()
            ->where('equipment_id', $image->equipment_id)
            ->where('image_path', $path)
            ->where('id', '!=', $image->id)
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException('This image path already exists for this equipment.');
        }

        $image->update(['image_path' => $path]);
    }

    public static function deleteMasterImage(InventoryMasterImage $image): void
    {
        $masterId = (int) $image->inventory_master_id;
        self::deleteLocalFileIfStored($image->image_path);
        $image->delete();
        InventoryImageSyncService::ensureMasterPrimary($masterId);
    }

    public static function deleteEquipmentImage(EquipmentImage $image): void
    {
        $equipmentId = (int) $image->equipment_id;
        self::deleteLocalFileIfStored($image->image_path);
        $image->delete();
        InventoryImageSyncService::ensureEquipmentPrimary($equipmentId);
    }

    public static function setMasterPrimary(int $inventoryMasterId, InventoryMasterImage $image): void
    {
        if ((int) $image->inventory_master_id !== $inventoryMasterId) {
            abort(404);
        }

        DB::transaction(function () use ($inventoryMasterId, $image) {
            InventoryMasterImage::query()
                ->where('inventory_master_id', $inventoryMasterId)
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);
        });
    }

    public static function setEquipmentPrimary(int $equipmentId, EquipmentImage $image): void
    {
        if ((int) $image->equipment_id !== $equipmentId) {
            abort(404);
        }

        DB::transaction(function () use ($equipmentId, $image) {
            EquipmentImage::query()
                ->where('equipment_id', $equipmentId)
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);
        });
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public static function reorderMasterImages(int $inventoryMasterId, array $orderedIds): void
    {
        self::applySortOrder(
            InventoryMasterImage::class,
            'inventory_master_id',
            $inventoryMasterId,
            $orderedIds
        );
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public static function reorderEquipmentImages(int $equipmentId, array $orderedIds): void
    {
        self::applySortOrder(
            EquipmentImage::class,
            'equipment_id',
            $equipmentId,
            $orderedIds
        );
    }

    /**
     * @param  class-string  $modelClass
     * @param  list<int>  $orderedIds
     */
    private static function applySortOrder(string $modelClass, string $scopeColumn, int $scopeId, array $orderedIds): void
    {
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));
        if ($orderedIds === []) {
            return;
        }

        $validIds = $modelClass::query()
            ->where($scopeColumn, $scopeId)
            ->whereIn('id', $orderedIds)
            ->pluck('id')
            ->all();

        if (count($validIds) !== count($orderedIds)) {
            throw new \InvalidArgumentException('Invalid image order.');
        }

        $sort = 0;
        foreach ($orderedIds as $id) {
            $modelClass::query()->where('id', $id)->update(['sort_order' => $sort++]);
        }
    }

    private static function resolvePath(?UploadedFile $file, ?string $imagePath, string $uploadDir): string
    {
        if ($file) {
            return self::storeUploadedFile($file, $uploadDir);
        }

        $path = trim((string) $imagePath);
        if ($path === '') {
            throw new \InvalidArgumentException('Provide an image file or image URL/path.');
        }

        return $path;
    }
}
