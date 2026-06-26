<?php

namespace App\Jobs;

use App\Services\InventoryAi\AiRequestPacer;
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

    /** @var list<int> */
    public array $inventoryMasterIds;

    /** Default ensures queued jobs serialized before this property existed still run. */
    public bool $retryIncomplete = false;

    public ?string $batchRunId = null;

    /**
     * @param  list<int>  $inventoryMasterIds
     */
    public function __construct(array $inventoryMasterIds, bool $retryIncomplete = false, ?string $batchRunId = null)
    {
        $this->inventoryMasterIds = $inventoryMasterIds;
        $this->retryIncomplete = $retryIncomplete;
        $this->batchRunId = $batchRunId;
        $this->onQueue((string) config('inventory_ai.queue', 'default'));
    }

    public function handle(): void
    {
        Log::info('Inventory specification enrichment batch started.', [
            'count' => count($this->inventoryMasterIds),
            'retry_incomplete' => $this->retryIncomplete,
            'batch_run_id' => $this->batchRunId,
        ]);

        foreach ($this->inventoryMasterIds as $index => $inventoryMasterId) {
            try {
                $delaySeconds = (int) round($index * AiRequestPacer::secondsBetweenRequests());

                EnrichInventorySpecificationJob::dispatch($inventoryMasterId, $this->retryIncomplete, $this->batchRunId)
                    ->delay(now()->addSeconds($delaySeconds));
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
