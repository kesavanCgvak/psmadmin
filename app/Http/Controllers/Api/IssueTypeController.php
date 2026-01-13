<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IssueType;
use Illuminate\Http\JsonResponse;

class IssueTypeController extends Controller
{
    /**
     * Get list of active issue types
     */
    public function index(): JsonResponse
    {
        try {
            $issueTypes = IssueType::select('id', 'name')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $issueTypes,
                'message' => 'Issue types fetched successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch issue types',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
