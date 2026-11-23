<?php

namespace App\Jobs;

use App\Contracts\SmsProvider;
use App\Exceptions\RetryableSmsException;
use App\Models\Company;
use App\Models\SmsLog;
use App\Services\SmsLogger;
use App\Services\SupplierSmsNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSupplierSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    /** Twilio error codes that will not succeed on retry. */
    private const PERMANENT_TWILIO_CODES = [21211, 21212, 21408, 21610, 21612, 21614, 21617];

    public function __construct(
        public int $supplyJobId,
        public string $requestName,
        public string $jobBeginDate,
        public int $supplierCompanyId,
        public ?int $dateFormatId,
        public ?int $rentalJobId = null,
        public string $customerName = '',
        public ?int $smsLogId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SmsProvider $smsProvider, SmsLogger $smsLogger): void
    {
        Log::info('SendSupplierSmsJob: starting.', [
            'supply_job_id' => $this->supplyJobId,
            'supplier_company_id' => $this->supplierCompanyId,
            'sms_log_id' => $this->smsLogId,
            'driver' => config('services.sms.driver'),
            'provider' => class_basename($smsProvider),
            'attempt' => $this->attempts(),
        ]);

        $smsLog = $this->smsLogId ? SmsLog::query()->find($this->smsLogId) : null;

        if (! $smsLog || in_array($smsLog->status, [SmsLog::STATUS_SENT, SmsLog::STATUS_DELIVERED], true)) {
            Log::info('SendSupplierSmsJob: nothing to send.', [
                'supply_job_id' => $this->supplyJobId,
                'sms_log_id' => $this->smsLogId,
                'status' => $smsLog?->status,
            ]);

            return;
        }

        $company = Company::with('defaultContactProfile')->find($this->supplierCompanyId);
        $mobile = $company ? SupplierSmsNotifier::getSupplierMobile($company) : null;

        if (! $company || $mobile === null || $mobile === '' || ! $smsProvider->isValidMobile($mobile)) {
            $reason = SmsLog::SKIP_MOBILE_INVALID;
            if (! $company) {
                $reason = SmsLog::SKIP_COMPANY_MISSING;
            } elseif ($mobile === null || $mobile === '') {
                $reason = SmsLog::SKIP_MOBILE_MISSING;
            }

            $smsLogger->markFailed($smsLog, $reason, null, $this->attempts());

            return;
        }

        if (! $this->claimAttempt($smsLog)) {
            Log::info('SendSupplierSmsJob: another worker already claimed this SMS.', [
                'supply_job_id' => $this->supplyJobId,
                'sms_log_id' => $smsLog->id,
            ]);

            return;
        }

        $smsLog->refresh();
        $message = $smsLog->message ?: SupplierSmsNotifier::buildMessage(
            $this->customerName,
            $this->jobBeginDate,
            $this->dateFormatId,
            $this->requestName
        );

        Log::debug('SendSupplierSmsJob: sending SMS via provider.', [
            'supply_job_id' => $this->supplyJobId,
            'sms_log_id' => $smsLog->id,
            'mobile_preview' => SupplierSmsNotifier::maskMobile($mobile),
            'message_length' => strlen($message),
        ]);

        $result = $smsProvider->sendSms($mobile, $message);

        if ($this->wasAccepted($result)) {
            $smsLogger->markSent(
                $smsLog,
                $result['message_id'] ?? null,
                $result['response'] ?? null,
                $this->attempts()
            );

            Log::info('SendSupplierSmsJob: SMS accepted by provider.', [
                'supply_job_id' => $this->supplyJobId,
                'sms_log_id' => $smsLog->id,
                'message_id' => $result['message_id'] ?? null,
                'provider_status' => $result['response']['status'] ?? null,
                'mobile_preview' => SupplierSmsNotifier::maskMobile($mobile),
            ]);

            return;
        }

        $error = $smsLogger->sanitizeError($result['error'] ?? 'Unknown') ?? 'Unknown';
        $permanent = $this->isPermanentFailure($result);
        $final = $permanent || $this->attempts() >= $this->tries();

        Log::error('SendSupplierSmsJob: SMS send failed.', [
            'supply_job_id' => $this->supplyJobId,
            'sms_log_id' => $smsLog->id,
            'error' => $error,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries(),
            'permanent' => $permanent,
            'will_retry' => ! $final,
        ]);

        if ($final) {
            $smsLogger->markFailed(
                $smsLog,
                ($permanent ? 'Permanent: ' : '').$error,
                $result['response'] ?? null,
                $this->attempts()
            );

            return;
        }

        $smsLogger->recordRetryableFailure(
            $smsLog,
            $error,
            $result['response'] ?? null,
            $this->attempts()
        );

        throw new RetryableSmsException($error);
    }

    /**
     * Get the number of times the job may be attempted.
     */
    public function tries(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * Delay between queue retries. Tests retry immediately.
     */
    public function backoff(): int
    {
        return app()->runningUnitTests() ? 0 : 60;
    }

    /**
     * Handle a job failure after the queue gives up, or immediately on the sync driver.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('SendSupplierSmsJob failed permanently.', [
            'supply_job_id' => $this->supplyJobId,
            'sms_log_id' => $this->smsLogId,
            'error' => app(SmsLogger::class)->sanitizeError($exception?->getMessage()),
        ]);

        if (! $this->smsLogId) {
            return;
        }

        $log = SmsLog::query()->find($this->smsLogId);

        if (! $log || in_array($log->status, [SmsLog::STATUS_SENT, SmsLog::STATUS_DELIVERED], true)) {
            return;
        }

        app(SmsLogger::class)->markFailed(
            $log,
            $exception?->getMessage() ?? 'Job failed permanently',
            $log->provider_response,
            $this->attempts()
        );
    }

    /**
     * Claim this queue attempt. A second worker with the same attempt updates zero rows.
     */
    private function claimAttempt(SmsLog $smsLog): bool
    {
        $attempt = max(1, $this->attempts());

        return SmsLog::query()
            ->whereKey($smsLog->id)
            ->where('status', SmsLog::STATUS_PENDING)
            ->where('attempts', $attempt - 1)
            ->update([
                'attempts' => $attempt,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Twilio acceptance requires a message SID and a status that is still in flight or sent.
     *
     * @param  array<string, mixed>  $result
     */
    private function wasAccepted(array $result): bool
    {
        if (empty($result['success']) || empty($result['message_id'])) {
            return false;
        }

        $status = is_array($result['response'] ?? null) ? ($result['response']['status'] ?? null) : null;

        return ! in_array($status, ['failed', 'undelivered'], true);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isPermanentFailure(array $result): bool
    {
        $error = (string) ($result['error'] ?? '');

        if (str_contains($error, 'Invalid mobile number') || str_contains($error, 'not configured')) {
            return true;
        }

        $body = is_array($result['response'] ?? null) ? ($result['response']['body'] ?? null) : null;
        $code = is_array($body) ? (int) ($body['code'] ?? 0) : 0;

        return in_array($code, self::PERMANENT_TWILIO_CODES, true);
    }
}
