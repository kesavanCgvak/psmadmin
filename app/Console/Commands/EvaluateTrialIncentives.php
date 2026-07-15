<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Subscription;
use App\Services\TrialIncentiveService;
use Illuminate\Console\Command;

class EvaluateTrialIncentives extends Command
{
    protected $signature = 'subscriptions:evaluate-trial-incentives';

    protected $description = 'Reconcile provider inventory milestones and extend trials when earned';

    public function handle(TrialIncentiveService $trialIncentiveService): int
    {
        if (! $trialIncentiveService->isEnabled()) {
            $this->info('Trial incentives are disabled or payment is off. Skipping.');

            return self::SUCCESS;
        }

        $companyIds = Subscription::query()
            ->where('account_type', 'provider')
            ->where('stripe_status', 'trialing')
            ->whereNotNull('company_id')
            ->pluck('company_id')
            ->unique()
            ->filter();

        $applied = 0;

        foreach ($companyIds as $companyId) {
            $company = Company::query()->find($companyId);
            if (! $company || $company->account_type !== 'provider') {
                continue;
            }

            if (($company->subscription_mode ?? 'paid') === 'free') {
                continue;
            }

            $result = $trialIncentiveService->evaluateAndApply((int) $companyId);
            if ($result['applied']) {
                $applied++;
                $this->line("Company {$companyId}: +{$result['bonus_months_applied']} month(s) at {$result['product_count']} products");
            }
        }

        $this->info("Trial incentive reconciliation complete. Companies updated: {$applied}");

        return self::SUCCESS;
    }
}
