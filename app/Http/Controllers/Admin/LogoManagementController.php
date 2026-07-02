<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LogoManagementController extends Controller
{
    /**
     * Display companies where the user has allowed promotional logo use.
     */
    public function index()
    {
        $companies = Company::query()
            ->where('logo_available_for_promotion', true)
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->orderByDesc('logo_promotion_consent_at')
            ->paginate(config('app.admin_list_per_page'));

        return view('admin.logo-management.index', compact('companies'));
    }

    /**
     * Admin toggle for promotional logo approval (does not change user consent).
     */
    public function updateAdminStatus(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'logo_promotion_admin_enabled' => 'required|in:0,1,true,false',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $enabled = (bool) $request->boolean('logo_promotion_admin_enabled');

        if ($enabled && empty($company->logo)) {
            return redirect()->back()
                ->with('error', 'Cannot enable admin promotional approval without an uploaded company logo.');
        }

        $company->applyLogoPromotionAdminStatus($enabled);

        $status = $enabled ? 'enabled' : 'disabled';

        $redirectRoute = $request->input('redirect_to') === 'company'
            ? route('admin.companies.show', $company)
            : route('admin.logo-management.index');

        return redirect($redirectRoute)
            ->with('success', "Admin promotional logo approval {$status} for {$company->name}.");
    }
}
