<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionTrialIncentiveGrant;
use App\Services\TrialIncentiveService;
use Illuminate\Http\Request;

class TrialIncentiveManagementController extends Controller
{
    public function __construct(
        protected TrialIncentiveService $trialIncentiveService
    ) {}

    /**
     * List providers who earned trial bonus months and grant history.
     */
    public function index(Request $request)
    {
        $perPage = config('app.admin_list_per_page', 25);

        $stats = [
            'total_grants' => SubscriptionTrialIncentiveGrant::count(),
            'total_bonus_months' => (int) SubscriptionTrialIncentiveGrant::sum('bonus_months'),
            'companies_rewarded' => SubscriptionTrialIncentiveGrant::distinct('company_id')->count('company_id'),
            'trialing_providers' => Subscription::query()
                ->where('account_type', 'provider')
                ->where('stripe_status', 'trialing')
                ->count(),
        ];

        $companiesQuery = Company::query()
            ->whereHas('trialIncentiveGrants')
            ->with(['subscription'])
            ->withSum('trialIncentiveGrants as total_bonus_months', 'bonus_months')
            ->withCount('trialIncentiveGrants as grant_count')
            ->withMax('trialIncentiveGrants as last_granted_at', 'granted_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $companiesQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhereHas('users', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('date_from')) {
            $companiesQuery->whereHas('trialIncentiveGrants', function ($query) use ($request) {
                $query->whereDate('granted_at', '>=', $request->date_from);
            });
        }

        if ($request->filled('date_to')) {
            $companiesQuery->whereHas('trialIncentiveGrants', function ($query) use ($request) {
                $query->whereDate('granted_at', '<=', $request->date_to);
            });
        }

        $companies = $companiesQuery
            ->orderByDesc('last_granted_at')
            ->paginate($perPage)
            ->withQueryString();

        foreach ($companies as $company) {
            $company->incentive_progress = $this->trialIncentiveService->getProgressForCompany((int) $company->id);
            $company->provider_owner = $company->users()->where('is_admin', 1)->with('profile')->first()
                ?? $company->users()->with('profile')->first();
        }

        $grantsQuery = SubscriptionTrialIncentiveGrant::query()
            ->with(['company', 'subscription.user.profile'])
            ->orderByDesc('granted_at');

        if ($request->filled('company_id')) {
            $grantsQuery->where('company_id', $request->company_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $grantsQuery->whereHas('company', function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $grantsQuery->whereDate('granted_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $grantsQuery->whereDate('granted_at', '<=', $request->date_to);
        }

        $grants = $grantsQuery
            ->paginate($perPage, ['*'], 'grants_page')
            ->withQueryString();

        $filteredCompany = $request->filled('company_id')
            ? Company::find($request->company_id)
            : null;

        return view('admin.trial-incentives.index', compact(
            'stats',
            'companies',
            'grants',
            'filteredCompany'
        ));
    }
}
