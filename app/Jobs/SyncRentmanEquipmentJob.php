<?php

namespace App\Jobs;

use App\Models\CompanyIntegration;
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
            Log::info('Rentman equipment sync job started.', [
                'company_id' => $this->companyId,
            ]);

            $service = new RentmanService($this->companyId);
            $service->syncAllEquipmentFromApi();

            CompanyIntegration::query()
                ->where('company_id', $this->companyId)
                ->where('integration_type', 'rentman')
                ->update([
                    'last_fetched_at' => now(),
                    'last_synced_at' => now(),
                ]);

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
