<?php

namespace App\Jobs;

use App\Services\InventoryAi\Exceptions\AiProviderException;
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

    public int $inventoryMasterId;

    /** Default ensures queued jobs serialized before this property existed still run. */
    public bool $retryIncomplete = false;

    public function __construct(int $inventoryMasterId, bool $retryIncomplete = false)
    {
        $this->inventoryMasterId = $inventoryMasterId;
        $this->retryIncomplete = $retryIncomplete;
        $this->onQueue((string) config('inventory_ai.queue', 'default'));
    }

    public function handle(InventorySpecificationEnrichmentService $service): void
    {
        try {
            $result = $service->enrichProduct($this->inventoryMasterId, $this->retryIncomplete);

            Log::info('Inventory specification enrichment job completed.', [
                'inventory_master_id' => $this->inventoryMasterId,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            if ($this->shouldFailWithoutRetry($e)) {
                $service->recordProviderFailure($this->inventoryMasterId, $e);

                Log::error('Inventory specification enrichment job failed (non-retryable).', [
                    'inventory_master_id' => $this->inventoryMasterId,
                    'error_category' => $e instanceof AiProviderException ? $e->category : null,
                    'error' => $e->getMessage(),
                ]);

                $this->fail($e);

                return;
            }

            Log::error('Inventory specification enrichment job failed.', [
                'inventory_master_id' => $this->inventoryMasterId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function shouldFailWithoutRetry(Throwable $e): bool
    {
        if (!$e instanceof AiProviderException) {
            return false;
        }

        if (!$e->isRetryable()) {
            return true;
        }

        return in_array($e->category, [
            AiProviderException::CATEGORY_QUOTA_EXCEEDED,
            AiProviderException::CATEGORY_INVALID_API_KEY,
            AiProviderException::CATEGORY_CONFIGURATION,
            AiProviderException::CATEGORY_INVALID_JSON,
        ], true);
    }
}
