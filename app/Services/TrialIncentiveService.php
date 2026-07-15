<?php

namespace App\Services;

use App\Helpers\EmailHelper;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionTrialIncentiveGrant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrialIncentiveService
{
    public function __construct(
        protected StripeSubscriptionService $stripeSubscriptionService
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('subscription_incentives.enabled', true)
            && Setting::isPaymentEnabled();
    }

    public function countQualifiedProducts(int $companyId): int
    {
        $qualified = config('subscription_incentives.qualified', []);

        $query = Equipment::query()->where('company_id', $companyId);

        if ($qualified['require_product_id'] ?? true) {
            $query->whereNotNull('product_id');
        }

        if ($qualified['require_rental_price'] ?? true) {
            $minPrice = (float) ($qualified['min_rental_price'] ?? 0.01);
            $query->where('rental_price', '>=', $minPrice);
        }

        return $query->count();
    }

    /**
     * @return array{
     *   enabled: bool,
     *   qualified_product_count: int,
     *   bonus_months_earned: int,
     *   total_free_months_earned: int,
     *   max_total_free_months: int,
     *   base_trial_months: int,
     *   next_milestone: ?array,
     *   milestones: array<int, array>,
     *   trial_ends_at: ?string
     * }
     */
    public function getProgressForCompany(int $companyId): array
    {
        $baseTrialMonths = (int) ceil(
            (config('subscription_plans.provider.default.trial_days', 60)) / 30
        );
        $maxTotalMonths = (int) config('subscription_incentives.max_total_free_months', 9);
        $milestones = $this->milestones();
        $productCount = $this->countQualifiedProducts($companyId);
        $subscription = $this->resolveProviderSubscription($companyId);
        $grantedMilestones = $this->grantedMilestoneProducts($subscription);

        $bonusMonthsEarned = 0;
        $milestoneProgress = [];

        foreach ($milestones as $milestone) {
            $threshold = (int) $milestone['products'];
            $bonusMonths = (int) $milestone['bonus_months'];
            $isGranted = in_array($threshold, $grantedMilestones, true);

            if ($isGranted) {
                $bonusMonthsEarned += $bonusMonths;
            }

            $milestoneProgress[] = [
                'products' => $threshold,
                'bonus_months' => $bonusMonths,
                'reached' => $productCount >= $threshold,
                'granted' => $isGranted,
                'products_remaining' => max(0, $threshold - $productCount),
            ];
        }

        $nextMilestone = null;
        foreach ($milestoneProgress as $item) {
            if (! $item['granted']) {
                $nextMilestone = [
                    'products' => $item['products'],
                    'bonus_months' => $item['bonus_months'],
                    'products_remaining' => $item['products_remaining'],
                ];
                break;
            }
        }

        return [
            'enabled' => $this->isEnabled(),
            'qualified_product_count' => $productCount,
            'bonus_months_earned' => $bonusMonthsEarned,
            'total_free_months_earned' => min($maxTotalMonths, $baseTrialMonths + $bonusMonthsEarned),
            'max_total_free_months' => $maxTotalMonths,
            'base_trial_months' => $baseTrialMonths,
            'next_milestone' => $nextMilestone,
            'milestones' => $milestoneProgress,
            'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
        ];
    }

    /**
     * Evaluate milestones and extend trial when newly earned.
     *
     * @return array{applied: bool, bonus_months_applied: int, grants: array<int, array>, product_count: int}
     */
    public function evaluateAndApply(int $companyId): array
    {
        $empty = [
            'applied' => false,
            'bonus_months_applied' => 0,
            'grants' => [],
            'product_count' => $this->countQualifiedProducts($companyId),
        ];

        if (! $this->isEnabled()) {
            return $empty;
        }

        $company = Company::query()->find($companyId);
        if (! $company || $company->account_type !== 'provider') {
            return $empty;
        }

        if (($company->subscription_mode ?? 'paid') === 'free') {
            return $empty;
        }

        $subscription = $this->resolveProviderSubscription($companyId);
        if (! $subscription || ! $subscription->stripe_subscription_id) {
            return $empty;
        }

        if (! $subscription->isOnTrial()) {
            return $empty;
        }

        $productCount = $this->countQualifiedProducts($companyId);
        $pendingMilestones = $this->pendingMilestones($subscription, $productCount);

        if ($pendingMilestones === []) {
            return array_merge($empty, ['product_count' => $productCount]);
        }

        $maxBonusMonths = $this->maxBonusMonths();
        $alreadyEarnedBonus = $this->bonusMonthsEarned($subscription);
        $remainingBonusCap = max(0, $maxBonusMonths - $alreadyEarnedBonus);

        if ($remainingBonusCap <= 0) {
            return array_merge($empty, ['product_count' => $productCount]);
        }

        $monthsToApply = 0;
        $grantsToCreate = [];

        foreach ($pendingMilestones as $milestone) {
            $bonus = (int) $milestone['bonus_months'];
            if ($monthsToApply + $bonus > $remainingBonusCap) {
                break;
            }

            $monthsToApply += $bonus;
            $grantsToCreate[] = $milestone;
        }

        if ($monthsToApply <= 0) {
            return array_merge($empty, ['product_count' => $productCount]);
        }

        return DB::transaction(function () use (
            $subscription,
            $companyId,
            $productCount,
            $monthsToApply,
            $grantsToCreate
        ) {
            $subscription = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->first();

            if (! $subscription || ! $subscription->isOnTrial()) {
                return [
                    'applied' => false,
                    'bonus_months_applied' => 0,
                    'grants' => [],
                    'product_count' => $productCount,
                ];
            }

            $stillPending = $this->pendingMilestones($subscription, $productCount);
            $stillToApply = [];
            $applyMonths = 0;
            $maxBonusMonths = $this->maxBonusMonths();
            $alreadyEarnedBonus = $this->bonusMonthsEarned($subscription);
            $remainingBonusCap = max(0, $maxBonusMonths - $alreadyEarnedBonus);

            foreach ($stillPending as $milestone) {
                $bonus = (int) $milestone['bonus_months'];
                if ($applyMonths + $bonus > $remainingBonusCap) {
                    break;
                }
                $applyMonths += $bonus;
                $stillToApply[] = $milestone;
            }

            if ($applyMonths <= 0) {
                return [
                    'applied' => false,
                    'bonus_months_applied' => 0,
                    'grants' => [],
                    'product_count' => $productCount,
                ];
            }

            $updatedSubscription = $this->stripeSubscriptionService->extendTrialByMonths(
                $subscription,
                $applyMonths
            );

            $createdGrants = [];
            $now = now();

            foreach ($stillToApply as $milestone) {
                SubscriptionTrialIncentiveGrant::create([
                    'subscription_id' => $updatedSubscription->id,
                    'company_id' => $companyId,
                    'milestone_products' => (int) $milestone['products'],
                    'bonus_months' => (int) $milestone['bonus_months'],
                    'product_count_at_grant' => $productCount,
                    'granted_at' => $now,
                ]);

                $createdGrants[] = [
                    'milestone_products' => (int) $milestone['products'],
                    'bonus_months' => (int) $milestone['bonus_months'],
                ];
            }

            $this->sendGrantNotification($companyId, $updatedSubscription, $createdGrants, $productCount);

            Log::info('Trial incentive grants applied', [
                'company_id' => $companyId,
                'subscription_id' => $updatedSubscription->id,
                'product_count' => $productCount,
                'bonus_months_applied' => $applyMonths,
                'grants' => $createdGrants,
                'trial_ends_at' => $updatedSubscription->trial_ends_at?->toIso8601String(),
            ]);

            return [
                'applied' => true,
                'bonus_months_applied' => $applyMonths,
                'grants' => $createdGrants,
                'product_count' => $productCount,
            ];
        });
    }

    protected function resolveProviderSubscription(int $companyId): ?Subscription
    {
        return Subscription::query()
            ->where('company_id', $companyId)
            ->where('account_type', 'provider')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<int, array{products: int, bonus_months: int}>
     */
    protected function milestones(): array
    {
        return config('subscription_incentives.milestones', []);
    }

    protected function maxBonusMonths(): int
    {
        $baseTrialMonths = (int) ceil(
            (config('subscription_plans.provider.default.trial_days', 60)) / 30
        );
        $maxTotal = (int) config('subscription_incentives.max_total_free_months', 9);

        return max(0, $maxTotal - $baseTrialMonths);
    }

    protected function bonusMonthsEarned(?Subscription $subscription): int
    {
        if (! $subscription) {
            return 0;
        }

        return (int) SubscriptionTrialIncentiveGrant::query()
            ->where('subscription_id', $subscription->id)
            ->sum('bonus_months');
    }

    /**
     * @return array<int, int>
     */
    protected function grantedMilestoneProducts(?Subscription $subscription): array
    {
        if (! $subscription) {
            return [];
        }

        return SubscriptionTrialIncentiveGrant::query()
            ->where('subscription_id', $subscription->id)
            ->pluck('milestone_products')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @return array<int, array{products: int, bonus_months: int}>
     */
    protected function pendingMilestones(?Subscription $subscription, int $productCount): array
    {
        if (! $subscription) {
            return [];
        }

        $granted = $this->grantedMilestoneProducts($subscription);
        $pending = [];

        foreach ($this->milestones() as $milestone) {
            $threshold = (int) $milestone['products'];
            if ($productCount >= $threshold && ! in_array($threshold, $granted, true)) {
                $pending[] = [
                    'products' => $threshold,
                    'bonus_months' => (int) $milestone['bonus_months'],
                ];
            }
        }

        return $pending;
    }

  /**
   * @param  array<int, array{milestone_products: int, bonus_months: int}>  $grants
   */
    protected function sendGrantNotification(
        int $companyId,
        Subscription $subscription,
        array $grants,
        int $productCount
    ): void {
        $company = Company::query()->with('providerOwner')->find($companyId);
        $owner = $company?->providerOwner;

        if (! $owner?->email) {
            return;
        }

        $totalBonusMonths = array_sum(array_column($grants, 'bonus_months'));
        $trialEndDate = $subscription->trial_ends_at
            ? $subscription->trial_ends_at->format('F j, Y')
            : null;

        try {
            EmailHelper::send('trialIncentiveGranted', [
                'user_full_name' => trim(($owner->profile_first_name ?? '').' '.($owner->profile_last_name ?? '')) ?: $owner->username,
                'company_name' => $company->name,
                'product_count' => $productCount,
                'bonus_months_applied' => $totalBonusMonths,
                'trial_end_date' => $trialEndDate,
                'grants_summary' => collect($grants)
                    ->map(fn ($grant) => "{$grant['bonus_months']} month(s) at {$grant['milestone_products']} products")
                    ->implode(', '),
                'current_year' => (string) now()->year,
            ], function ($message) use ($owner) {
                $message->to($owner->email);
            });
        } catch (\Throwable $e) {
            Log::warning('Failed to send trial incentive email', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
