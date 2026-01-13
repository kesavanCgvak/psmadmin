<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TermsAndConditions;
use Illuminate\Http\Request;

class TermsAndConditionsController extends Controller
{
    /**
     * Get the current terms and conditions
     * This endpoint is public and does not require authentication
     */
    public function index()
    {
        try {
            $terms = TermsAndConditions::getCurrent();
            
            if (!$terms) {
                return response()->json([
                    'success' => false,
                    'message' => 'Terms and conditions not found',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Terms and conditions retrieved successfully',
                'data' => [
                    'id' => $terms->id,
                    'description' => $terms->description,
                    'updated_at' => $terms->updated_at->toISOString(),
                    'created_at' => $terms->created_at->toISOString(),
                ]
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve terms and conditions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve terms and conditions',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
