<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DateFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class DateFormatController extends Controller
{
    /**
     * Return all date formats for use in the frontend.
     */
    public function index(): JsonResponse
    {
        try {
            $dateFormats = DateFormat::select('id', 'format', 'name')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $dateFormats,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error fetching date formats: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch date formats.',
            ], 500);
        }
    }
}


