<?php

namespace App\Jobs;

use App\Contracts\SmsProvider;
use App\Models\SmsLog;
use App\Services\SmsLogger;
use App\Services\SupplierSmsNotifier;
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

    /** Persisted across retries so a single SMS maps to a single log row. */
    public ?int $smsLogId = null;

    public function __construct(
        public int $supplyJobId,
        public string $requestName,
        public string $jobBeginDate,
        public int $supplierCompanyId,
        public ?int $dateFormatId,
        public ?int $rentalJobId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SmsProvider $smsProvider, SmsLogger $smsLogger): void
    {
        $driver = config('services.sms.driver');

        Log::info('SendSupplierSmsJob: starting.', [
            'supply_job_id' => $this->supplyJobId,
            'supplier_company_id' => $this->supplierCompanyId,
            'driver' => $driver,
            'provider' => class_basename($smsProvider),
            'attempt' => $this->attempts(),
        ]);

        $cacheKey = "supplier_sms_sent:{$this->supplyJobId}";

        if (Cache::has($cacheKey)) {
            Log::info('SendSupplierSmsJob: SMS already sent for this supply job, skipping (dedup).', [
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
        $profile = $company?->defaultContactProfile;
        $mobile = $company ? SupplierSmsNotifier::getSupplierMobile($company) : null;

        if (empty($mobile) || !$smsProvider->isValidMobile($mobile)) {
            Log::warning('SendSupplierSmsJob: no valid mobile for supplier, skipping.', [
                'supply_job_id' => $this->supplyJobId,
                'supplier_company_id' => $this->supplierCompanyId,
                'mobile_preview' => SupplierSmsNotifier::maskMobile($mobile),
                'company_found' => (bool) $company,
            ]);
            return;
        }

        // Create (or reuse across retries) a pending audit record before sending.
        $smsLog = $this->smsLogId ? SmsLog::find($this->smsLogId) : null;

        if (!$smsLog) {
            $smsLog = $smsLogger->createPending([
                'provider' => $driver,
                'message' => $message,
                'recipient_name' => $profile?->full_name,
                'phone_number' => $mobile,
                'company_id' => $company?->id,
                'company_name' => $company?->name,
                'contact_person_name' => $profile?->full_name,
                'contact_person_mobile' => $profile?->mobile,
                'related_type' => 'Rental Request',
                'related_id' => $this->rentalJobId,
                'sent_by' => SmsLog::SENT_BY_SYSTEM,
                'attempts' => $this->attempts(),
            ]);
            $this->smsLogId = $smsLog?->id;
        }

        Log::debug('SendSupplierSmsJob: sending SMS via provider.', [
            'supply_job_id' => $this->supplyJobId,
            'sms_log_id' => $this->smsLogId,
            'driver' => $driver,
            'mobile_preview' => SupplierSmsNotifier::maskMobile($mobile),
            'message' => $message,
            'message_length' => strlen($message),
        ]);

        $result = $smsProvider->sendSms($mobile, $message);

        if ($result['success']) {
            Cache::put($cacheKey, true, self::DEDUP_TTL_SECONDS);

            $smsLogger->markSent(
                $smsLog,
                $result['message_id'] ?? null,
                $result['response'] ?? null,
                $this->attempts()
            );

            Log::info('SendSupplierSmsJob: SMS sent successfully.', [
                'supply_job_id' => $this->supplyJobId,
                'sms_log_id' => $this->smsLogId,
                'driver' => $driver,
                'message_id' => $result['message_id'] ?? null,
                'mobile_preview' => SupplierSmsNotifier::maskMobile($mobile),
            ]);
        } else {
            $willRetry = $this->attempts() < $this->tries();

            $smsLogger->markFailed(
                $smsLog,
                $result['error'] ?? 'Unknown',
                $result['response'] ?? null,
                $this->attempts()
            );

            Log::error('SendSupplierSmsJob: SMS send failed.', [
                'supply_job_id' => $this->supplyJobId,
                'sms_log_id' => $this->smsLogId,
                'driver' => $driver,
                'error' => $result['error'] ?? 'Unknown',
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries(),
                'will_retry' => $willRetry,
            ]);

            if ($willRetry) {
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
            'sms_log_id' => $this->smsLogId,
            'error' => $exception?->getMessage(),
        ]);

        if ($this->smsLogId) {
            app(SmsLogger::class)->markFailed(
                SmsLog::find($this->smsLogId),
                $exception?->getMessage() ?? 'Job failed permanently',
                null,
                $this->attempts()
            );
        }
    }
}
