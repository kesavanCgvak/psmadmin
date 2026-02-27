<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class CompanyUserLimitController extends Controller
{
    /**
     * Get company user limit (public endpoint for frontend)
     */
    public function getLimit()
    {
        try {
            $limit = Setting::getCompanyUserLimit();
            
            return response()->json([
                'success' => true,
                'company_user_limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve company user limit',
            ], 500);
        }
    }
}
