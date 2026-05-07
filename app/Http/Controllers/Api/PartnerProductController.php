<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerProductController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $providerCompany = $request->attributes->get('provider_company');
        if (!$providerCompany) {
            return response()->json([
                'success' => false,
                'message' => 'Provider company context is missing.',
            ], 403);
        }

        $keyword = trim((string) $request->query('q'));
        $perPage = (int) $request->query('per_page', config('app.admin_list_per_page', 25));
        $page = (int) $request->query('page', 1);
        $offset = ($page - 1) * $perPage;

        $baseQuery = DB::table('company_inventory as ci')
            ->join('inventory_master as p', 'ci.product_id', '=', 'p.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('sub_categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('ci.company_id', $providerCompany->id)
            ->where(function ($query) use ($keyword) {
                $like = '%' . strtolower($keyword) . '%';
                $query->whereRaw('LOWER(p.model) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(b.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(c.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(sc.name) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(TRIM(CONCAT_WS(' ', b.name, p.model))) LIKE ?", [$like]);
            });

        $total = (clone $baseQuery)->count('ci.id');

        $products = $baseQuery
            ->select(
                'p.id as product_id',
                DB::raw("TRIM(CONCAT_WS(' ', b.name, p.model)) as product_name"),
                'p.model as model_name',
                'p.psm_code',
                'b.id as brand_id',
                'b.name as brand_name',
                'c.id as category_id',
                'c.name as category_name',
                'sc.id as sub_category_id',
                'sc.name as sub_category_name',
                'ci.quantity',
                'ci.rental_price',
                'ci.software_code'
            )
            ->orderBy('p.id', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Products fetched successfully.',
            'data' => $products,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ]);
    }

    public function details(Request $request, int $productId): JsonResponse
    {
        $providerCompany = $request->attributes->get('provider_company');
        if (!$providerCompany) {
            return response()->json([
                'success' => false,
                'message' => 'Provider company context is missing.',
            ], 403);
        }

        $product = DB::table('company_inventory as ci')
            ->join('inventory_master as p', 'ci.product_id', '=', 'p.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('sub_categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('ci.company_id', $providerCompany->id)
            ->where('p.id', $productId)
            ->select(
                'p.id as product_id',
                DB::raw("TRIM(CONCAT_WS(' ', b.name, p.model)) as product_name"),
                'p.model as model_name',
                'p.psm_code',
                'p.webpage_url',
                'p.is_verified',
                'p.height',
                'p.width',
                'p.length',
                'p.weight',
                'p.linear_unit_id',
                'p.weight_unit_id',
                'p.replacement_price',
                'p.source',
                'p.country_of_origin',
                'p.iso_code_2',
                'p.iso_code_3',
                'p.hsn_code',
                'b.id as brand_id',
                'b.name as brand_name',
                'c.id as category_id',
                'c.name as category_name',
                'sc.id as sub_category_id',
                'sc.name as sub_category_name',
                'ci.quantity',
                'ci.rental_price',
                'ci.software_code'
            )
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product details fetched successfully.',
            'data' => $product,
        ]);
    }
}

