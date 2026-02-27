<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserRestrictionsController extends Controller
{
    /**
     * Display user restrictions settings
     */
    public function index()
    {
        $userLimit = Setting::getCompanyUserLimit();
        
        return view('admin.user-restrictions.index', compact('userLimit'));
    }

    /**
     * Update user restrictions settings
     */
    public function update(Request $request)
    {
        $request->validate([
            'company_user_limit' => 'required|integer|min:1|max:100',
        ], [
            'company_user_limit.required' => 'User limit is required.',
            'company_user_limit.integer' => 'User limit must be a number.',
            'company_user_limit.min' => 'User limit must be at least 1.',
            'company_user_limit.max' => 'User limit cannot exceed 100.',
        ]);

        try {
            $limit = (int) $request->input('company_user_limit');
            Setting::setCompanyUserLimit($limit);

            // Clear all setting caches to ensure fresh data
            Cache::flush();

            return redirect()
                ->route('admin.user-restrictions.index')
                ->with('success', "User limit updated successfully to {$limit} users per company.");
        } catch (\Exception $e) {
            \Log::error('Failed to update user restrictions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Failed to update user restrictions: ' . $e->getMessage());
        }
    }
}
