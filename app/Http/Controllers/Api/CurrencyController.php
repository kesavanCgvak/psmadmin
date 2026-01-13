<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class CurrencyController extends Controller
{
    /**
     * Return all currencies for use in the frontend.
     */
    public function index(): JsonResponse
    {
        try {
            $currencies = Currency::select('id', 'code', 'name', 'symbol')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $currencies,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error fetching currencies: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch currencies.',
            ], 500);
        }
    }
}

