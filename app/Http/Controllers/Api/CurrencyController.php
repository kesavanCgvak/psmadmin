<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;

class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        $currencies = Currency::select('id', 'code', 'name', 'symbol')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $currencies
        ]);
    }
}
