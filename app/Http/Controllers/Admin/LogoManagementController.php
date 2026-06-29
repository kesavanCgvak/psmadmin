<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LogoManagementController extends Controller
{
    /**
     * Display companies that allow their logo for promotional materials.
     */
    public function index()
    {
        $companies = Company::query()
            ->where('logo_available_for_promotion', true)
            ->whereNotNull('logo')
            ->orderByDesc('logo_promotion_consent_at')
            ->paginate(config('app.admin_list_per_page'));

        return view('admin.logo-management.index', compact('companies'));
    }

    /**
     * Admin override for promotional logo consent.
     */
    public function updateConsent(Request $request, Company $company)
    {
        $validator = Validator::make($request->all(), [
            'logo_available_for_promotion' => 'required|in:0,1,true,false',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $enabled = (bool) $request->boolean('logo_available_for_promotion');

        if ($enabled && empty($company->logo)) {
            return redirect()->back()
                ->with('error', 'Cannot enable promotional logo use without an uploaded company logo.');
        }

        $company->applyLogoPromotionConsent($enabled);

        $status = $enabled ? 'enabled' : 'disabled';

        $redirectRoute = $request->input('redirect_to') === 'company'
            ? route('admin.companies.show', $company)
            : route('admin.logo-management.index');

        return redirect($redirectRoute)
            ->with('success', "Promotional logo consent {$status} for {$company->name}.");
    }
}
