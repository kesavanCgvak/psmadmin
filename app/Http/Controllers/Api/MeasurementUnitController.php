<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LinearUnit;
use App\Models\WeightUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class MeasurementUnitController extends Controller
{
    /**
     * Return active linear units (height, width, length).
     */
    public function linearUnits(): JsonResponse
    {
        try {
            $units = LinearUnit::query()
                ->select('id', 'name', 'code', 'system')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Linear units fetched successfully.',
                'data' => $units,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error fetching linear units: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch linear units.',
            ], 500);
        }
    }

    /**
     * Return active weight units.
     */
    public function weightUnits(): JsonResponse
    {
        try {
            $units = WeightUnit::query()
                ->select('id', 'name', 'code', 'system')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Weight units fetched successfully.',
                'data' => $units,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error fetching weight units: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch weight units.',
            ], 500);
        }
    }
}
