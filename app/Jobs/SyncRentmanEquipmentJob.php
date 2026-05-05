<?php

namespace App\Jobs;

use App\Services\RentmanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncRentmanEquipmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $companyId)
    {
    }

    public function handle(): void
    {
        try {
            $service = new RentmanService($this->companyId);
            $service->syncAllEquipmentFromApi();

            Log::info('Rentman equipment sync completed.', [
                'company_id' => $this->companyId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Rentman equipment sync job failed.', [
                'company_id' => $this->companyId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
