<?php

namespace App\Jobs;

use App\Services\SupplierSmsNotifier;
use App\Services\TextMagicService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendSupplierSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Cache key TTL for duplicate prevention (24 hours). */
    private const DEDUP_TTL_SECONDS = 86400;

    public function __construct(
        public int $supplyJobId,
        public string $requestName,
        public string $jobBeginDate,
        public int $supplierCompanyId,
        public ?int $dateFormatId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TextMagicService $textMagicService): void
    {
        $cacheKey = "supplier_sms_sent:{$this->supplyJobId}";

        if (Cache::has($cacheKey)) {
            Log::info('SendSupplierSmsJob: SMS already sent for this supply job, skipping.', [
                'supply_job_id' => $this->supplyJobId,
            ]);
            return;
        }

        $phpFormat = SupplierSmsNotifier::getPhpDateFormat($this->dateFormatId);
        $formattedDate = Carbon::parse($this->jobBeginDate)->format($phpFormat);

        $message = sprintf(
            'A request from "%s" starting on "%s" has been emailed to you from Pro Subrental Marketplace. Please check your email and respond as soon as possible.',
            $this->requestName,
            $formattedDate
        );

        $company = \App\Models\Company::with('defaultContactProfile')->find($this->supplierCompanyId);
        $mobile = $company ? SupplierSmsNotifier::getSupplierMobile($company) : null;

        if (empty($mobile) || !$textMagicService->isValidMobile($mobile)) {
            Log::warning('SendSupplierSmsJob: no valid mobile for supplier, skipping.', [
                'supply_job_id' => $this->supplyJobId,
            ]);
            return;
        }

        $result = $textMagicService->sendSms($mobile, $message);

        if ($result['success']) {
            Cache::put($cacheKey, true, self::DEDUP_TTL_SECONDS);
        } else {
            Log::error('SendSupplierSmsJob: TextMagic send failed.', [
                'supply_job_id' => $this->supplyJobId,
                'error' => $result['error'] ?? 'Unknown',
            ]);

            if ($this->attempts() < $this->tries()) {
                $this->release(60);
            }
        }
    }

    /**
     * Get the number of times the job may be attempted.
     */
    public function tries(): int
    {
        return 3;
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('SendSupplierSmsJob failed permanently.', [
            'supply_job_id' => $this->supplyJobId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
