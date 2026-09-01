<?php

namespace App\Jobs;

use App\Models\RentalJob;
use App\Models\SupplyJob;
use App\Services\HireTrackIntegrationService;
use App\Support\HireTrackIntegrationDebugLog;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Runs synchronously when dispatched (does not implement ShouldQueue).
 * HireTrack import txt + provider email is not queued — mirrors Flex/Rentman create-rental jobs.
 */
class CreateHireTrackCsvFromRentalRequestJob
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $rentalJobId) {}

    public function handle(): void
    {
        $rentalJob = RentalJob::query()
            ->with([
                'user.profile',
                'user.company',
                'supplyJobs.provider.rentalSoftware',
                'supplyJobs.provider.getDefaultcontact',
                'supplyJobs.products.product.brand',
                'supplyJobs.comments',
            ])
            ->find($this->rentalJobId);

        if (!$rentalJob || !$rentalJob->user) {
            HireTrackIntegrationDebugLog::warning($this->rentalJobId, null, 'HIRETRACK_CSV_JOB', 'ABORTED', [
                'reason' => 'rental_job_or_requester_missing',
            ]);

            return;
        }

        HireTrackIntegrationDebugLog::step(
            $rentalJob->id,
            null,
            'HIRETRACK_CSV_JOB',
            'STARTED',
            [
                'supply_jobs_count' => $rentalJob->supplyJobs->count(),
                'execution' => 'synchronous',
            ],
            'Process each supply job: check HireTrack rental software, generate import txt, email provider',
        );

        foreach ($rentalJob->supplyJobs as $supplyJob) {
            $this->processSupplyJob($rentalJob, $supplyJob);
        }

        HireTrackIntegrationDebugLog::step(
            $rentalJob->id,
            null,
            'HIRETRACK_CSV_JOB',
            'ALL_PROVIDERS_DONE',
            [],
            'Done — inspect storage/logs/hiretrack-integration.log',
        );
    }

    protected function processSupplyJob(RentalJob $rentalJob, SupplyJob $supplyJob): void
    {
        $providerId = (int) $supplyJob->provider_id;

        HireTrackIntegrationDebugLog::resetStepCounter($rentalJob->id, $providerId);

        HireTrackIntegrationDebugLog::step(
            $rentalJob->id,
            $providerId,
            'PROVIDER_PROCESSING',
            'STARTED',
            ['supply_job_id' => $supplyJob->id],
            'Check whether provider uses HireTrack rental software',
        );

        if (!HireTrackIntegrationService::checkCompanyIntegration($providerId)) {
            HireTrackIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'CHECK_INTEGRATION',
                'SKIPPED',
                ['reason' => 'not_hiretrack_provider'],
                'Skip HireTrack for this provider; process next supply job',
            );

            return;
        }

        HireTrackIntegrationDebugLog::step(
            $rentalJob->id,
            $providerId,
            'CHECK_INTEGRATION',
            'SUCCESS',
            ['supply_job_id' => $supplyJob->id],
            'Generate HireTrack import txt from products with software_code and email the provider',
        );

        try {
            (new HireTrackIntegrationService())->processSupplyJob($rentalJob, $supplyJob);

            HireTrackIntegrationDebugLog::step(
                $rentalJob->id,
                $providerId,
                'PROVIDER_PROCESSING',
                'FINISHED',
                ['supply_job_id' => $supplyJob->id],
                'Process next supply job',
            );
        } catch (\Throwable $e) {
            HireTrackIntegrationDebugLog::error($rentalJob->id, $providerId, 'HIRETRACK_CSV_FLOW', 'FAILED', [
                'supply_job_id' => $supplyJob->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
