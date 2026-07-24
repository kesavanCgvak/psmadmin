<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\DefaultImagePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PromotionalLogoController extends Controller
{
    /**
     * Public API: active promotional provider logos (user consent + admin approval), sorted by display order.
     */
    public function index(): JsonResponse
    {
        try {
            $companies = Company::query()
                ->promotionalLogosActive()
                ->get(['id', 'name', 'logo', 'account_type', 'logo_promotion_sort_order']);

            $data = $companies->map(function (Company $company) {
                $logoPath = DefaultImagePath::companyLogo($company->logo);

                return [
                    'id' => $company->id,
                    'company_name' => $company->name,
                    'account_type' => $company->account_type,
                    'logo_path' => $logoPath,
                    'logo_url' => DefaultImagePath::companyLogoUrl($company->logo),
                    'sort_order' => (int) $company->logo_promotion_sort_order,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Promotional logos retrieved successfully',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error fetching promotional logos', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch promotional logos',
            ], 500);
        }
    }
}
