<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SupplyJob;
use App\Models\RentalJobProduct;
use Illuminate\Support\Facades\Auth;

class SupplyJobController extends Controller
{
    /**
     * List supply jobs for a company (lightweight).
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Validate query param
        $validated = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        $companyId = (int) $request->query('company_id');

        // Security check
        if ($user->company_id !== $companyId && !$user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: you do not belong to this company.'
            ], 403);
        }

        try {
            $supplyJobs = SupplyJob::with([
                'rentalJob:id,name,from_date,to_date',
                'products:id,supply_job_id,product_id',
                'products.product:id,model,brand_id',
                'products.product.brand:id,name'
            ])
                ->select(['id', 'rental_job_id', 'provider_id', 'status', 'created_at'])
                ->where('provider_id', $companyId)
                ->orderBy('created_at', 'desc')
                ->get();

            $data = $supplyJobs->map(function ($job) {
                return [
                    'id' => $job->id,
                    'name' => $job->rentalJob->name,
                    'rental_job_id' => $job->rentalJob->id,
                    'start_date' => $job->rentalJob->from_date,
                    'end_date' => $job->rentalJob->to_date,
                    'status' => $job->status,
                    'products' => $job->products->map(function ($sp) {
                        $brandName = $sp->product->brand->name ?? '';
                        $productName = $sp->product->model ?? '';
                        return [
                            'id' => $sp->product_id,
                            'name' => trim("{$brandName} - {$productName}", ' -')
                        ];
                    })->values()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            \Log::error("Failed to fetch supply jobs", [
                'company_id' => $companyId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch supply jobs',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], 500);
        }
    }

    /**
     * Show detailed supply job info for a provider.
     */
    public function show(Request $request, int $id)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        $companyId = (int) $request->query('company_id');

        if ($user->company_id !== $companyId && !$user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: you do not belong to this company.'
            ], 403);
        }

        try {
            $supplyJob = SupplyJob::with([
                'rentalJob:id,name,from_date,to_date,delivery_address',
                'products.product:id,model,brand_id',
                'products.product.brand:id,name',
                'offers:id,supply_job_id,version,total_price,status',
            ])
                ->where('id', $id)
                ->where('provider_id', $companyId)
                ->firstOrFail();

            $products = $supplyJob->products->map(function ($sp) use ($supplyJob) {
                $requestedQuantity = RentalJobProduct::where('rental_job_id', $supplyJob->rental_job_id)
                    ->where('product_id', $sp->product_id)
                    ->value('requested_quantity');

                $brandName = $sp->product->brand->name ?? '';
                $productName = $sp->product->model ?? '';

                return [
                    'id' => $sp->product_id,
                    'name' => trim("{$brandName} - {$productName}", ' -'),
                    'requested_quantity' => (int) $requestedQuantity,
                    'offered_quantity' => (int) ($sp->offered_quantity ?? 0),
                    'price_per_unit' => $sp->price_per_unit !== null ? (float) $sp->price_per_unit : null,
                ];
            });

            $data = [
                'id' => $supplyJob->id,
                'name' => $supplyJob->rentalJob->name,
                'rental_job_id' => $supplyJob->rentalJob->id,
                'start_date' => $supplyJob->rentalJob->from_date,
                'end_date' => $supplyJob->rentalJob->to_date,
                'delivery_address' => $supplyJob->rentalJob->delivery_address,
                'status' => $supplyJob->status,
                'products' => $products,
                'offers' => $supplyJob->offers->map(function ($offer) {
                    return [
                        'id' => $offer->id,
                        'version' => $offer->version,
                        'total_price' => (string) $offer->total_price,
                        'status' => $offer->status,
                    ];
                })->values()
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Supply job not found.'
            ], 404);
        } catch (\Throwable $e) {
            \Log::error("Failed to fetch supply job details", [
                'supply_job_id' => $id,
                'company_id' => $companyId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch supply job details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], 500);
        }
    }
}
