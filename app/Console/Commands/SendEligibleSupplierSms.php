<?php

namespace App\Console\Commands;

use App\Models\SupplyJob;
use App\Services\SupplierSmsNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendEligibleSupplierSms extends Command
{
    protected $signature = 'sms:send-eligible-supplier-notifications';

    protected $description = 'Send supplier SMS for rental requests whose start date is within the next 7 days';

    public function handle(): int
    {
        $today = Carbon::today();
        $until = $today->copy()->addDays(7);

        $supplyJobs = SupplyJob::query()
            ->with(['rentalJob.user.company', 'rentalJob.user.profile'])
            ->whereHas('rentalJob', function ($query) use ($today, $until) {
                $query->whereDate('from_date', '>=', $today->toDateString())
                    ->whereDate('from_date', '<=', $until->toDateString());
            })
            ->orderBy('id')
            ->get();

        $dispatched = 0;

        foreach ($supplyJobs as $supplyJob) {
            $rentalJob = $supplyJob->rentalJob;
            $user = $rentalJob?->user;

            if (! $rentalJob || ! $user) {
                continue;
            }

            if (SupplierSmsNotifier::notifyIfNeeded($supplyJob, $rentalJob, $user)) {
                $dispatched++;
            }
        }

        $this->info("Checked {$supplyJobs->count()} supply job(s); dispatched {$dispatched} supplier SMS job(s).");

        return self::SUCCESS;
    }
}
