<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnrichInventorySpecificationsBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  list<int>  $inventoryMasterIds
     */
    public function __construct(public array $inventoryMasterIds)
    {
        $this->onQueue((string) config('inventory_ai.queue', 'default'));
    }

    public function handle(): void
    {
        Log::info('Inventory specification enrichment batch started.', [
            'count' => count($this->inventoryMasterIds),
        ]);

        foreach ($this->inventoryMasterIds as $inventoryMasterId) {
            try {
                EnrichInventorySpecificationJob::dispatch($inventoryMasterId);
            } catch (Throwable $e) {
                Log::error('Failed to dispatch inventory enrichment job.', [
                    'inventory_master_id' => $inventoryMasterId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Inventory specification enrichment batch dispatched.', [
            'count' => count($this->inventoryMasterIds),
        ]);
    }
}
