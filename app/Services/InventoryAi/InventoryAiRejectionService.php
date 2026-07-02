<?php

namespace App\Services\InventoryAi;

use App\Models\InventoryMasterAiRejection;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

final class InventoryAiRejectionService
{
    public function isRejected(int $inventoryMasterId): bool
    {
        return InventoryMasterAiRejection::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->exists();
    }

    public function record(
        int $inventoryMasterId,
        string $productName,
        string $rejectionReason,
        string $rejectionCategory,
        ?string $batchRunId = null,
        ?int $specId = null,
    ): InventoryMasterAiRejection {
        $rejection = InventoryMasterAiRejection::query()->updateOrCreate(
            ['inventory_master_id' => $inventoryMasterId],
            [
                'product_name' => $productName,
                'rejection_reason' => mb_substr($rejectionReason, 0, 2000),
                'rejection_category' => $rejectionCategory,
                'rejected_at' => now(),
                'batch_run_id' => $batchRunId,
                'spec_id' => $specId,
            ],
        );

        Log::info('Inventory AI rejection recorded.', [
            'inventory_master_id' => $inventoryMasterId,
            'rejection_category' => $rejectionCategory,
            'batch_run_id' => $batchRunId,
            'spec_id' => $specId,
        ]);

        return $rejection;
    }

    public function recordForProduct(
        Product $product,
        string $rejectionReason,
        string $rejectionCategory,
        ?string $batchRunId = null,
        ?int $specId = null,
    ): InventoryMasterAiRejection {
        return $this->record(
            $product->id,
            (string) $product->model,
            $rejectionReason,
            $rejectionCategory,
            $batchRunId,
            $specId,
        );
    }

    public function clear(int $inventoryMasterId): void
    {
        $deleted = InventoryMasterAiRejection::query()
            ->where('inventory_master_id', $inventoryMasterId)
            ->delete();

        if ($deleted > 0) {
            Log::info('Inventory AI rejection cleared after successful enrichment.', [
                'inventory_master_id' => $inventoryMasterId,
            ]);
        }
    }

    public function resolveProductName(int $inventoryMasterId): string
    {
        return (string) (Product::query()
            ->where('id', $inventoryMasterId)
            ->value('model') ?? "Product #{$inventoryMasterId}");
    }
}
