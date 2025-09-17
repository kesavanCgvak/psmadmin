<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use Illuminate\Http\JsonResponse;

class SubCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $subCategories = SubCategory::select('id', 'name', 'category_id')->get();

            if ($subCategories->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subcategories found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $subCategories,
                'message' => 'Subcategories fetched successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch subcategories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getByCategory(int $id): JsonResponse
    {
        try {
            $subCategories = SubCategory::select('id', 'name')
                ->where('category_id', $id)
                ->get();

            if ($subCategories->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subcategories found for this category',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $subCategories,
                'message' => 'Subcategories fetched successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch subcategories',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
