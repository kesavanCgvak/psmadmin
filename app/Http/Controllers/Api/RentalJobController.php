<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RentalJob;
use App\Models\RentalJobProduct;
use App\Models\SupplyJob;
use App\Models\SupplyJobProduct;
use App\Models\RentalJobComment;
use App\Models\RentalJobOffer;
use App\Models\JobOffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Company;
use App\Models\Product;
use App\Models\Equipment;

class RentalJobController extends Controller
{

    /**
     * LIST: Rental jobs created by the logged-in user (summary).
     * Supports filters . Secure & light-weight.
     */
    public function index(Request $request)
    {
        $user = auth('api')->user(); // JWT guard

        // Validate query params
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'in_negotiation', 'accepted', 'cancelled', 'completed'])],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        try {
            $query = RentalJob::query()
                ->with([
                    'products.product.brand',
                    'supplyJobs:id,rental_job_id'
                ])
                ->where('user_id', $user->id);

            // optional filters
            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }
            if (!empty($validated['from_date'])) {
                $query->whereDate('from_date', '>=', $validated['from_date']);
            }
            if (!empty($validated['to_date'])) {
                $query->whereDate('to_date', '<=', $validated['to_date']);
            }

            $jobs = $query->orderByDesc('created_at')->get();

            // Transform payload
            $jobsTransformed = $jobs->map(function (RentalJob $job) {
                return [
                    'id' => $job->id,
                    'name' => $job->name,
                    'from_date' => $job->from_date,
                    'to_date' => $job->to_date,
                    'delivery_address' => $job->delivery_address,
                    'status' => $job->status,
                    'products' => $job->products->map(function ($rp) {
                        $brand = $rp->product->brand->name ?? '';
                        $prod = $rp->product->name ?? $rp->product->model ?? '';
                        return [
                            'id' => $rp->product_id,
                            'name' => trim($brand . ' - ' . $prod, ' -'),
                            'requested_quantity' => (int) $rp->requested_quantity,
                        ];
                    })->values(),
                    'provider_responses_count' => $job->supplyJobs->count(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $jobsTransformed,
            ]);

        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rental jobs',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }
    }


    /**
     * DETAIL: Basic rental job details only.
     * Returns basic info + list of suppliers (basic info only).
     */
    public function show(Request $request, $id)
    {
        $user = auth('api')->user();

        try {
            $job = RentalJob::with([
                'supplyJobs:id,rental_job_id,provider_id,status', // Basic supply job info
                'supplyJobs.providerCompany:id,name', // Company name only
            ])->findOrFail($id);

            // Security: only owner or admin can view
            if ($job->user_id !== $user->id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this rental job.'
                ], 403);
            }

            // Build suppliers array with basic info only
            $suppliers = $job->supplyJobs->map(function ($sj) {
                return [
                    'supply_job_id' => $sj->id,
                    'rental_job_id' => $sj->rental_job_id,
                    'company_id' => $sj->providerCompany->id ?? null,
                    'company_name' => $sj->providerCompany->name ?? 'Unknown',
                    'status' => $sj->status,
                ];
            })->values();

            $payload = [
                'id' => $job->id,
                'name' => $job->name,
                'from_date' => $job->from_date,
                'to_date' => $job->to_date,
                'delivery_address' => $job->delivery_address,
                'status' => $job->status,
                'suppliers' => $suppliers,
            ];

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rental job not found.'
            ], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load rental job',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }
    }

    /**
     * SUPPLIER DETAILS: Get detailed supplier information for a specific supply job.
     * Returns only the products offered by this supplier, with requested vs supplied quantities, pricing, and latest offer.
     */
    public function supplierDetails(Request $request, int $rentalJobId, int $supplyJobId)
    {
        $user = auth('api')->user();

        try {
            // 1. Verify rental job exists and user has access
            $rentalJob = RentalJob::select(['id', 'user_id'])
                ->findOrFail($rentalJobId);

            if ($rentalJob->user_id !== $user->id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this rental job.'
                ], 403);
            }

            // 2. Load supply job with provider, products, offers
            $supplyJob = SupplyJob::with([
                'providerCompany:id,name,currency_id',
                'providerCompany.currency:id,name,code,symbol',
                'products.product' => function ($q) {
                    $q->select('id', 'model', 'brand_id')
                        ->with('brand:id,name');
                },
                'offers' => function ($q) {
                    $q->select('id', 'supply_job_id', 'version', 'total_price', 'status')
                        ->orderByDesc('version');
                },
            ])
                ->where('id', $supplyJobId)
                ->where('rental_job_id', $rentalJobId)
                ->firstOrFail();

            // 3. Fetch requested quantities from rental job products
            $requestedQuantities = RentalJobProduct::where('rental_job_id', $rentalJobId)
                ->pluck('requested_quantity', 'product_id');

            // 4. Build equipment details (only supplier’s products)
            $equipmentDetails = $supplyJob->products->map(function ($supplyProduct) use ($requestedQuantities) {
                $brand = $supplyProduct->product->brand->name ?? '';
                $productName = $supplyProduct->product->model ?? '';

                return [
                    'product_id' => $supplyProduct->product_id,
                    'equipment_name' => trim($brand . ' - ' . $productName, ' -'),
                    'required_quantity' => (int) ($requestedQuantities[$supplyProduct->product_id] ?? 0),
                    'can_supply' => (int) ($supplyProduct->offered_quantity ?? 0),
                    'price_per_unit' => $supplyProduct->price_per_unit !== null ? (float) $supplyProduct->price_per_unit : null,
                ];
            })->values();

            // 5. Get latest offer (if exists)
            $latestOffer = JobOffer::where('rental_job_id', $rentalJobId)
                ->where('supply_job_id', $supplyJob->id)
                ->orderByDesc('version')
                ->first();
            $loggedInCompany = $user->company_id;

            // Default
            $canSendOffer = false;
            $canCancel = false;

            if ($latestOffer) {

                if (in_array($latestOffer->status, ['accepted', 'cancelled'])) {
                    $canSendOffer = false;
                    $canCancel = false;
                } else {
                    if ($latestOffer->sender_company_id == $loggedInCompany) {
                        // User sent the last offer → cannot send new one
                        $canSendOffer = false;
                        $canCancel = false;
                    } else {
                        // Other company sent last offer → user can reply
                        $canSendOffer = true;
                        $canCancel = true;
                    }
                }
            }


            // 6. Build response payload
            $provider = $supplyJob->providerCompany;
            $currency = $provider?->currency;

            $payload = [
                'supply_job_id' => $supplyJob->id,
                'rental_job_id' => $supplyJob->rental_job_id,
                'company_id' => $provider->id,
                'company_name' => $provider->name,
                'currency' => $currency ? [
                    'id' => $currency->id,
                    'name' => $currency->name,
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                ] : null,
                'status' => $supplyJob->status,
                'equipment_details' => $equipmentDetails,
                'latest_offer' => $latestOffer ? [
                    'id' => $latestOffer->id,
                    'version' => (int) $latestOffer->version,
                    'total_price' => (string) $latestOffer->total_price,
                    'status' => $latestOffer->status,
                    'sender_company_id' => $latestOffer->sender_company_id,
                    'receiver_company_id' => $latestOffer->receiver_company_id,
                ] : null,
                'negotiation_controls' => [
                    'can_send_offer' => $canSendOffer,
                    'can_cancel_negotiation' => $canCancel,
                ],
                'comments_endpoint' => "/api/supply-jobs/{$supplyJob->id}/comments",
            ];
            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Log::warning("Supplier details not found", [
                'rental_job_id' => $rentalJobId,
                'supply_job_id' => $supplyJobId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Supply job not found.'
            ], 404);
        } catch (\Throwable $e) {
            \Log::error("Failed to load supplier details", [
                'error' => $e->getMessage(),
                'rental_job_id' => $rentalJobId,
                'supply_job_id' => $supplyJobId,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load supplier details.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }
    }
}
