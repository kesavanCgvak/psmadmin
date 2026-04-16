<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\JobRating;
use App\Models\SupplyJob;
use Illuminate\Http\Request;

class JobRatingsController extends Controller
{
    /**
     * Display job ratings overview for admins: all ratings, low-rated providers, jobs pending rating.
     */
    public function index(Request $request)
    {
        $tab = $request->get('tab', 'all');
        $lowRatingThreshold = 2;

        $stats = [
            'total_ratings' => JobRating::whereNotNull('rated_at')->count(),
            'low_rated' => JobRating::whereNotNull('rated_at')->where('rating', '<=', $lowRatingThreshold)->count(),
            'pending_rating' => SupplyJob::where('status', 'completed_pending_rating')->count(),
        ];

        $ratings = null;
        $pendingJobs = null;

        if ($tab === 'low-rated') {
            $query = JobRating::with([
                'supplyJob.rentalJob.user.company',
                'supplyJob.providerCompany',
                'rentalJob.user.company',
                'replies',
            ])
                ->whereNotNull('rated_at')
                ->where('rating', '<=', $lowRatingThreshold)
                ->orderBy('rated_at', 'desc');
            $ratings = $query->paginate(15)->withQueryString();
            $listTitle = 'Low-Rated Providers (rating ≤ ' . $lowRatingThreshold . ' stars)';
        } elseif ($tab === 'pending') {
            $query = SupplyJob::with([
                'rentalJob.user.company',
                'providerCompany',
                'rentalJob:id,name,from_date,to_date',
            ])
                ->where('status', 'completed_pending_rating')
                ->orderBy('updated_at', 'desc');
            $pendingJobs = $query->paginate(15)->withQueryString();
            $listTitle = 'Jobs Pending Rating';
        } else {
            $query = JobRating::with([
                'supplyJob.rentalJob.user.company',
                'supplyJob.providerCompany',
                'rentalJob.user.company',
                'replies',
            ])
                ->whereNotNull('rated_at')
                ->orderBy('rated_at', 'desc');
            $ratings = $query->paginate(15)->withQueryString();
            $listTitle = 'All Ratings';
        }

        $dateFormat = config('app.date_format', 'M d, Y');
        $datetimeFormat = config('app.datetime_format', 'M d, Y H:i');

        return view('admin.job-ratings.index', compact(
            'tab',
            'stats',
            'ratings',
            'pendingJobs',
            'listTitle',
            'lowRatingThreshold',
            'dateFormat',
            'datetimeFormat'
        ));
    }

    /**
     * Block a company (e.g. low-rated provider). Platform-level block by admin.
     */
    public function blockCompany(Request $request, Company $company)
    {
        $company->update(['blocked_by_admin_at' => now()]);
        return redirect()
            ->back()
            ->with('success', "Company \"{$company->name}\" has been blocked. They will not appear in provider listings until unblocked.");
    }

    /**
     * Unblock a company.
     */
    public function unblockCompany(Request $request, Company $company)
    {
        $company->update(['blocked_by_admin_at' => null]);
        return redirect()
            ->back()
            ->with('success', "Company \"{$company->name}\" has been unblocked.");
    }
}
