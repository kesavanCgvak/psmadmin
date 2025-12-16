<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\StripeSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionManagementController extends Controller
{
    protected $stripeService;

    public function __construct(StripeSubscriptionService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    /**
     * Display a listing of subscriptions.
     */
    public function index(Request $request)
    {
        // Build query with filters
        $query = Subscription::with(['user.profile', 'user.company']);

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('stripe_status', $request->status);
        }

        // Filter by account type
        if ($request->filled('account_type') && $request->account_type !== 'all') {
            $query->where('account_type', $request->account_type);
        }

        // Filter by plan
        if ($request->filled('plan') && $request->plan !== 'all') {
            $query->where('plan_name', $request->plan);
        }

        // Filter by payment status
        if ($request->filled('payment_status') && $request->payment_status !== 'all') {
            if ($request->payment_status === 'failed') {
                $query->whereIn('stripe_status', ['past_due', 'unpaid']);
            } elseif ($request->payment_status === 'active') {
                $query->whereIn('stripe_status', ['active', 'trialing']);
            }
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('username', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%")
                              ->orWhereHas('profile', function ($profileQuery) use ($search) {
                                  $profileQuery->where('full_name', 'like', "%{$search}%");
                              });
                })
                ->orWhereHas('user.company', function ($companyQuery) use ($search) {
                    $companyQuery->where('name', 'like', "%{$search}%");
                })
                ->orWhere('stripe_subscription_id', 'like', "%{$search}%")
                ->orWhere('stripe_customer_id', 'like', "%{$search}%");
            });
        }

        // Order by created_at descending
        $query->orderBy('created_at', 'desc');

        // Paginate results
        $subscriptions = $query->paginate(25)->withQueryString();

        // Calculate statistics
        $stats = [
            'total' => Subscription::count(),
            'active' => Subscription::whereIn('stripe_status', ['active', 'trialing'])->count(),
            'trialing' => Subscription::where('stripe_status', 'trialing')->count(),
            'payment_failed' => Subscription::whereIn('stripe_status', ['past_due', 'unpaid'])->count(),
            'canceled' => Subscription::where('stripe_status', 'canceled')->count(),
            'total_revenue' => Subscription::whereIn('stripe_status', ['active', 'trialing'])
                ->sum('amount'),
        ];

        // Get unique values for filters
        $statuses = Subscription::distinct()->pluck('stripe_status')->sort()->values();
        $accountTypes = Subscription::distinct()->pluck('account_type')->sort()->values();
        $plans = Subscription::distinct()->pluck('plan_name')->sort()->values();

        return view('admin.subscriptions.index', compact(
            'subscriptions',
            'stats',
            'statuses',
            'accountTypes',
            'plans'
        ));
    }

    /**
     * Display the specified subscription.
     */
    public function show($id)
    {
        $subscription = Subscription::with(['user.profile', 'user.company'])
            ->findOrFail($id);

        // Calculate days until trial end
        $daysUntilTrialEnd = null;
        if ($subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()) {
            $daysUntilTrialEnd = now()->diffInDays($subscription->trial_ends_at);
        }

        // Calculate days until period end
        $daysUntilPeriodEnd = null;
        if ($subscription->current_period_end && $subscription->current_period_end->isFuture()) {
            $daysUntilPeriodEnd = now()->diffInDays($subscription->current_period_end);
        }

        // Stripe dashboard URLs
        $stripeSubscriptionUrl = "https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}";
        $stripeCustomerUrl = "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}";

        return view('admin.subscriptions.show', compact(
            'subscription',
            'daysUntilTrialEnd',
            'daysUntilPeriodEnd',
            'stripeSubscriptionUrl',
            'stripeCustomerUrl'
        ));
    }

    /**
     * Sync subscription data from Stripe.
     */
    public function sync(Request $request, $id)
    {
        try {
            $subscription = Subscription::findOrFail($id);
            
            $syncedSubscription = $this->stripeService->syncSubscriptionFromStripe(
                $subscription->stripe_subscription_id
            );

            if ($syncedSubscription) {
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Subscription synced successfully',
                        'subscription' => $syncedSubscription->fresh(['user.profile', 'user.company']),
                    ]);
                }

                return redirect()
                    ->route('admin.subscriptions.show', $id)
                    ->with('success', 'Subscription synced successfully from Stripe.');
            }

            throw new \Exception('Failed to sync subscription');
        } catch (\Exception $e) {
            Log::error('Failed to sync subscription', [
                'subscription_id' => $id,
                'error' => $e->getMessage(),
            ]);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to sync subscription: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()
                ->route('admin.subscriptions.show', $id)
                ->with('error', 'Failed to sync subscription: ' . $e->getMessage());
        }
    }
}

