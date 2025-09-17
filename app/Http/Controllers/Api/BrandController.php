<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $brands = Brand::select('id', 'name')->get();

            return response()->json([
                'success' => true,
                'data'    => $brands,
                'message' => 'Brands fetched successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch brands',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}

