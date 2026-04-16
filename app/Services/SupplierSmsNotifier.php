<?php

namespace App\Services;

use App\Jobs\SendSupplierSmsJob;
use App\Models\Company;
use App\Models\DateFormat;
use App\Models\RentalJob;
use App\Models\SupplyJob;
use App\Models\User;
use Carbon\Carbon;
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

        return $profile && !empty($profile->mobile) ? trim($profile->mobile) : null;
    }

    /**
     * Get date format string for PHP Carbon from date_format_id.
     * Converts formats like MM/DD/YYYY to m/d/Y.
     */
    public static function getPhpDateFormat(?int $dateFormatId): string
    {
        if (!$dateFormatId) {
            return 'm/d/Y';
        }

        $dateFormat = DateFormat::find($dateFormatId);

        if (!$dateFormat || empty($dateFormat->format)) {
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
     * If conditions are met, dispatch SendSupplierSmsJob.
     * Does not throw - failures are logged and do not block request creation.
     */
    public static function notifyIfNeeded(SupplyJob $supplyJob, RentalJob $rentalJob, User $user): void
    {
        try {
            $jobBeginCarbon = Carbon::parse($rentalJob->from_date);

            if (!self::shouldSendSms($jobBeginCarbon)) {
                return;
            }

            $company = Company::with('defaultContactProfile')->find($supplyJob->provider_id);

            if (!$company) {
                Log::warning('SupplierSmsNotifier: supplier company not found.', [
                    'company_id' => $supplyJob->provider_id,
                ]);
                return;
            }

            $mobile = self::getSupplierMobile($company);

            if (empty($mobile) || !(new TextMagicService)->isValidMobile($mobile)) {
                Log::info('SupplierSmsNotifier: no valid mobile for supplier, skipping SMS.', [
                    'company_id' => $supplyJob->provider_id,
                ]);
                return;
            }

            $dateFormatId = $user->company?->date_format_id ?? $company->date_format_id;

            SendSupplierSmsJob::dispatch(
                $supplyJob->id,
                $rentalJob->name,
                $rentalJob->from_date?->format('Y-m-d') ?? (string) $rentalJob->from_date,
                $supplyJob->provider_id,
                $dateFormatId
            );
        } catch (\Throwable $e) {
            Log::error('SupplierSmsNotifier: failed to dispatch SMS job.', [
                'supply_job_id' => $supplyJob->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
