<?php

namespace App\Jobs;

use App\Services\InventoryAi\InventorySpecificationEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichInventorySpecificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180, 600];

    public function __construct(public int $inventoryMasterId)
    {
        $this->onQueue((string) config('inventory_ai.queue', 'default'));
    }

    public function handle(InventorySpecificationEnrichmentService $service): void
    {
        try {
            $result = $service->enrichProduct($this->inventoryMasterId);

            Log::info('Inventory specification enrichment job completed.', [
                'inventory_master_id' => $this->inventoryMasterId,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            Log::error('Inventory specification enrichment job failed.', [
                'inventory_master_id' => $this->inventoryMasterId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
