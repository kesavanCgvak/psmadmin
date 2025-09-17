<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $categories = Category::select('id', 'name')->get();

            return response()->json([
                'success' => true,
                'data'    => $categories,
                'message' => 'Categories fetched successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
