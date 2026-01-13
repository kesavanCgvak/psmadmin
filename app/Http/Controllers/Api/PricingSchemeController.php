<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PricingScheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class PricingSchemeController extends Controller
{
    /**
     * Return all pricing schemes for use in the frontend.
     */
    public function index(): JsonResponse
    {
        try {
            $pricingSchemes = PricingScheme::select('id', 'code', 'name')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pricingSchemes,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error fetching pricing schemes: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch pricing schemes.',
            ], 500);
        }
    }
}


