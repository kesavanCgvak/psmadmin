<?php

namespace App\Services;

use App\Contracts\SmsProvider;
use App\Jobs\SendSupplierSmsJob;
use App\Models\Company;
use App\Models\DateFormat;
use App\Models\RentalJob;
use App\Models\SmsLog;
use App\Models\SupplyJob;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierSmsNotifier
{
    /**
     * Check if SMS should be sent for a supply job:
     * - Job begin date (from_date) is less than 8 days from today
     * - Job begin date is not in the past
     */
    public static function shouldSendSms(Carbon $jobBeginDate): bool
    {
        $today = Carbon::today();

        if ($jobBeginDate->lt($today)) {
            return false;
        }

        $daysUntilJob = $today->diffInDays($jobBeginDate, false);

        return $daysUntilJob < 8;
    }

    /**
     * Get supplier mobile from company (default_contact_id → user_profiles.mobile).
     */
    public static function getSupplierMobile(Company $company): ?string
    {
        if (empty($company->default_contact_id)) {
            return null;
        }

        $profile = $company->defaultContactProfile ?? $company->defaultContact?->profile;

        return $profile && ! empty($profile->mobile) ? trim($profile->mobile) : null;
    }

    /**
     * Get date format string for PHP Carbon from date_format_id.
     * Converts formats like MM/DD/YYYY to m/d/Y.
     */
    public static function getPhpDateFormat(?int $dateFormatId): string
    {
        if (! $dateFormatId) {
            return 'm/d/Y';
        }

        $dateFormat = DateFormat::find($dateFormatId);

        if (! $dateFormat || empty($dateFormat->format)) {
            return 'm/d/Y';
        }

        // Map common format tokens (MM/DD/YYYY style) to PHP
        $map = [
            'MM' => 'm',
            'DD' => 'd',
            'YYYY' => 'Y',
            'YY' => 'y',
            'M' => 'n',
            'D' => 'j',
        ];

        $php = $dateFormat->format;
        foreach ($map as $token => $phpToken) {
            $php = str_replace($token, $phpToken, $php);
        }

        return $php ?: 'm/d/Y';
    }

    /**
     * Supplier SMS body. Shared by rental creation and the daily scheduler.
     */
    public static function buildMessage(string $customerName, string $jobBeginDate, ?int $dateFormatId, string $requestName): string
    {
        $formattedDate = Carbon::parse($jobBeginDate)->format(self::getPhpDateFormat($dateFormatId));

        return sprintf(
            'Pro Subrental Marketplace Request from "%s" starting on "%s" and titled "%s" has been emailed to you. Please check your email and respond as soon as possible.',
            $customerName,
            $formattedDate,
            $requestName
        );
    }

    /**
     * Send or log a supplier SMS when the rental start date is inside the 0–7 day window.
     * Does not throw — failures are logged and do not block request creation.
     *
     * @return bool True when a new send job was dispatched
     */
    public static function notifyIfNeeded(SupplyJob $supplyJob, RentalJob $rentalJob, User $user): bool
    {
        try {
            $jobBeginCarbon = Carbon::parse($rentalJob->from_date)->startOfDay();
            $daysUntilJob = Carbon::today()->diffInDays($jobBeginCarbon, false);

            Log::debug('SupplierSmsNotifier: evaluating SMS notification.', [
                'supply_job_id' => $supplyJob->id,
                'rental_job_id' => $rentalJob->id,
                'supplier_company_id' => $supplyJob->provider_id,
                'job_begin_date' => $jobBeginCarbon->toDateString(),
                'days_until_job' => $daysUntilJob,
            ]);

            $company = Company::with('defaultContactProfile')->find($supplyJob->provider_id);

            if ($jobBeginCarbon->lt(Carbon::today())) {
                self::skip($supplyJob, $rentalJob, $company, null, self::skipReason(SmsLog::SKIP_PAST, $supplyJob, $company));

                return false;
            }

            if (! self::shouldSendSms($jobBeginCarbon)) {
                Log::debug('SupplierSmsNotifier: skipping SMS (job begin date outside 0-7 day window).', [
                    'supply_job_id' => $supplyJob->id,
                    'days_until_job' => $daysUntilJob,
                ]);
                self::skip($supplyJob, $rentalJob, $company, null, self::skipReason(SmsLog::SKIP_OUTSIDE_WINDOW, $supplyJob, $company));

                return false;
            }

            if (! $company) {
                Log::warning('SupplierSmsNotifier: supplier company not found, skipping SMS.', [
                    'supply_job_id' => $supplyJob->id,
                    'company_id' => $supplyJob->provider_id,
                ]);
                self::skip(
                    $supplyJob,
                    $rentalJob,
                    null,
                    null,
                    self::skipReason(SmsLog::SKIP_COMPANY_MISSING, $supplyJob, null)
                );

                return false;
            }

            if (empty($company->default_contact_id)) {
                self::skip($supplyJob, $rentalJob, $company, null, self::skipReason(SmsLog::SKIP_CONTACT_MISSING, $supplyJob, $company));

                return false;
            }

            $mobile = self::getSupplierMobile($company);

            if ($mobile === null || $mobile === '') {
                Log::info('SupplierSmsNotifier: no mobile for supplier, skipping SMS.', [
                    'supply_job_id' => $supplyJob->id,
                    'company_id' => $supplyJob->provider_id,
                ]);
                self::skip($supplyJob, $rentalJob, $company, null, self::skipReason(SmsLog::SKIP_MOBILE_MISSING, $supplyJob, $company));

                return false;
            }

            if (! app(SmsProvider::class)->isValidMobile($mobile)) {
                Log::info('SupplierSmsNotifier: invalid mobile for supplier, skipping SMS.', [
                    'supply_job_id' => $supplyJob->id,
                    'company_id' => $supplyJob->provider_id,
                    'mobile_preview' => self::maskMobile($mobile),
                ]);
                self::skip($supplyJob, $rentalJob, $company, $mobile, self::skipReason(SmsLog::SKIP_MOBILE_INVALID, $supplyJob, $company));

                return false;
            }

            $dateFormatId = $user->company?->date_format_id ?? $company->date_format_id;
            $customerName = $user->company?->name
                ?? $user->profile?->full_name
                ?? $user->username
                ?? 'Unknown';
            $jobBeginDate = $rentalJob->from_date?->format('Y-m-d') ?? (string) $rentalJob->from_date;
            $message = self::buildMessage($customerName, $jobBeginDate, $dateFormatId ? (int) $dateFormatId : null, (string) $rentalJob->name);

            $smsLogId = self::reserveSend($supplyJob, $rentalJob, $company, $mobile, $message);

            if (! $smsLogId) {
                return false;
            }

            Log::info('SupplierSmsNotifier: dispatching supplier SMS job.', [
                'supply_job_id' => $supplyJob->id,
                'rental_job_id' => $rentalJob->id,
                'company_id' => $supplyJob->provider_id,
                'sms_log_id' => $smsLogId,
                'mobile_preview' => self::maskMobile($mobile),
                'days_until_job' => $daysUntilJob,
            ]);

            try {
                SendSupplierSmsJob::dispatch(
                    $supplyJob->id,
                    (string) $rentalJob->name,
                    $jobBeginDate,
                    (int) $supplyJob->provider_id,
                    $dateFormatId ? (int) $dateFormatId : null,
                    $rentalJob->id,
                    $customerName,
                    $smsLogId
                );
            } catch (\Throwable $e) {
                SmsLog::query()
                    ->whereKey($smsLogId)
                    ->where('status', SmsLog::STATUS_PENDING)
                    ->where('attempts', 0)
                    ->delete();

                throw $e;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('SupplierSmsNotifier: failed to dispatch SMS job.', [
                'supply_job_id' => $supplyJob->id,
                'error' => app(SmsLogger::class)->sanitizeError($e->getMessage()),
            ]);

            return false;
        }
    }

    /**
     * Mask a mobile number for safe logging, keeping only the last 3 digits.
     */
    public static function maskMobile(?string $mobile): string
    {
        if (empty($mobile)) {
            return 'none';
        }

        $digits = preg_replace('/\D/', '', $mobile);

        if (strlen($digits) <= 3) {
            return '***';
        }

        return '***'.substr($digits, -3);
    }

    /**
     * Keep the admin reason readable, and keep two suppliers on one rental distinct
     * when the company row itself is missing.
     */
    private static function skipReason(string $reason, SupplyJob $supplyJob, ?Company $company): string
    {
        if ($company) {
            return $reason;
        }

        return $reason.' Supply job '.$supplyJob->id.'.';
    }

    private static function skip(
        SupplyJob $supplyJob,
        RentalJob $rentalJob,
        ?Company $company,
        ?string $mobile,
        string $reason
    ): void {
        $profile = $company?->defaultContactProfile;

        app(SmsLogger::class)->recordSkip([
            'provider' => config('services.sms.driver'),
            'recipient_name' => $profile?->full_name,
            'phone_number' => $mobile,
            'company_id' => $company?->id,
            'company_name' => $company?->name,
            'contact_person_name' => $profile?->full_name,
            'contact_person_mobile' => $profile?->mobile,
            'related_type' => SmsLog::RELATED_RENTAL_REQUEST,
            'related_id' => $rentalJob->id,
            'sent_by' => SmsLog::SENT_BY_SYSTEM,
            'supply_job_id' => $supplyJob->id,
            'message' => $reason,
        ], $reason);
    }

    /**
     * Insert one pending log under a row lock, or return null when a send is already
     * sent, in flight, or permanently finished.
     */
    private static function reserveSend(
        SupplyJob $supplyJob,
        RentalJob $rentalJob,
        Company $company,
        string $mobile,
        string $message
    ): ?int {
        return DB::transaction(function () use ($supplyJob, $rentalJob, $company, $mobile, $message) {
            $logger = app(SmsLogger::class);
            $logger->lockSupplyJob($supplyJob->id);

            $logs = SmsLog::query()
                ->where('related_type', SmsLog::RELATED_RENTAL_REQUEST)
                ->where('related_id', $rentalJob->id)
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->get();

            $blocked = $logs->contains(function (SmsLog $log) {
                if (in_array($log->status, [SmsLog::STATUS_SENT, SmsLog::STATUS_DELIVERED, SmsLog::STATUS_PENDING], true)) {
                    return true;
                }

                return $log->status === SmsLog::STATUS_FAILED && ! $log->isOutsideWindowSkip();
            });

            if ($blocked) {
                Log::info('SupplierSmsNotifier: supplier SMS already sent, pending, or closed.', [
                    'supply_job_id' => $supplyJob->id,
                    'rental_job_id' => $rentalJob->id,
                    'company_id' => $company->id,
                ]);

                return null;
            }

            $profile = $company->defaultContactProfile;
            $smsLog = $logger->createPending([
                'provider' => config('services.sms.driver'),
                'message' => $message,
                'recipient_name' => $profile?->full_name,
                'phone_number' => $mobile,
                'company_id' => $company->id,
                'company_name' => $company->name,
                'contact_person_name' => $profile?->full_name,
                'contact_person_mobile' => $profile?->mobile,
                'related_type' => SmsLog::RELATED_RENTAL_REQUEST,
                'related_id' => $rentalJob->id,
                'sent_by' => SmsLog::SENT_BY_SYSTEM,
                'attempts' => 0,
            ]);

            return $smsLog?->id;
        });
    }
}
