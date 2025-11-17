<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SupplyJob;
use App\Models\RentalJobProduct;
use App\Models\JobOffer;
use App\Models\Currency;
use App\Models\SupplyJobProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Log;

class SupplyJobController extends Controller
{

    /**
     * List supply jobs for a company (lightweight) with filters and pagination.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Validate query params
        $validated = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'status' => 'nullable|string|in:pending,negotiating,accepted,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $companyId = (int) $request->input('company_id');
        $status = $request->input('status');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $perPage = $request->input('per_page', 20); // default 20 per page

        // Security check
        if ($user->company_id !== $companyId && !$user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: you do not belong to this company.'
            ], 403);
        }

        try {
            // Build query
            $query = SupplyJob::with([
                'rentalJob:id,name,from_date,to_date',
                'products:id,supply_job_id,product_id',
                'products.product:id,model,brand_id',
                'products.product.brand:id,name'
            ])
                ->select(['id', 'rental_job_id', 'provider_id', 'status', 'created_at'])
                ->where('provider_id', $companyId)
                ->orderBy('created_at', 'desc'); // newest first

            // Apply filters
            if ($status) {
                $query->where('status', $status);
            }
            if ($startDate) {
                $query->whereHas('rentalJob', function ($q) use ($startDate) {
                    $q->where('from_date', '>=', $startDate);
                });
            }
            if ($endDate) {
                $query->whereHas('rentalJob', function ($q) use ($endDate) {
                    $q->where('to_date', '<=', $endDate);
                });
            }

            // Paginate
            $paginated = $query->paginate($perPage);

            // Map response
            $data = $paginated->getCollection()->map(function ($job) {
                return [
                    'id' => $job->id,
                    'name' => $job->rentalJob?->name ?? '',
                    'rental_job_id' => $job->rentalJob?->id ?? null,
                    'start_date' => $job->rentalJob?->from_date ?? null,
                    'end_date' => $job->rentalJob?->to_date ?? null,
                    'status' => $job->status,
                    'products' => $job->products->map(function ($sp) {
                        $brandName = $sp->product?->brand?->name ?? '';
                        $productName = $sp->product?->model ?? '';
                        return [
                            'id' => $sp->product_id,
                            'name' => trim("{$brandName} - {$productName}", ' -')
                        ];
                    })->values()
                ];
            });

            // Keep pagination meta
            $response = [
                'success' => true,
                'data' => $data,
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ]
            ];

            return response()->json($response);

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
                'providerCompany:id,name,currency_id',
                'providerCompany.currency:id,name,code,symbol',
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

            $company = $supplyJob->providerCompany;
            $currency = $company?->currency;
            $latestOffer = JobOffer::where('rental_job_id', $supplyJob->rental_job_id)
                ->where('supply_job_id', $supplyJob->id)
                ->orderBy('version', 'desc')
                ->select([
                    'id',
                    'rental_job_id',
                    'supply_job_id',
                    'version',
                    'total_price',
                    'status',
                    'sender_company_id',
                    'receiver_company_id',
                    'last_offer_by',
                    'currency_id',
                    'created_at'
                ])
                ->first();

            $loggedInCompany = $user->company_id;

            // Default values
            $canSendOffer = false;
            $canCancelNegotiation = false;

            // If we have an offer, adjust permission
            if ($latestOffer) {

                // If latest offer is accepted/cancelled, disable everything
                if (in_array($latestOffer->status, ['accepted', 'cancelled'])) {
                    $canSendOffer = false;
                    $canCancelNegotiation = false;

                } else {
                    // Latest offer is pending
                    if ($latestOffer->sender_company_id == $loggedInCompany) {
                        // User sent last offer -> cannot send again
                        $canSendOffer = false;
                        $canCancelNegotiation = false;
                    } else {
                        // Other company sent the last offer -> can reply
                        $canSendOffer = true;
                        $canCancelNegotiation = true;
                    }
                }
            }


            $data = [
                'id' => $supplyJob->id,
                'name' => $supplyJob->rentalJob->name,
                'rental_job_id' => $supplyJob->rentalJob->id,
                'start_date' => $supplyJob->rentalJob->from_date,
                'end_date' => $supplyJob->rentalJob->to_date,
                'packing_date' => $supplyJob->packing_date,
                'delivery_date' => $supplyJob->delivery_date,
                'return_date' => $supplyJob->return_date,
                'unpacking_date' => $supplyJob->unpacking_date,
                'delivery_address' => $supplyJob->rentalJob->delivery_address,
                'status' => $supplyJob->status,
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'currency' => $currency ? [
                        'id' => $currency->id,
                        'name' => $currency->name,
                        'code' => $currency->code,
                        'symbol' => $currency->symbol,
                    ] : null,
                ],
                'products' => $products,
                'offers' => $latestOffer ? [
                    'id' => $latestOffer->id,
                    'version' => $latestOffer->version,
                    'total_price' => (string) $latestOffer->total_price,
                    'status' => $latestOffer->status,
                    'sender_company_id' => $latestOffer->sender_company_id,
                    'receiver_company_id' => $latestOffer->receiver_company_id,
                    'last_offer_by' => $latestOffer->last_offer_by,
                    'currency_id' => $latestOffer->currency_id,
                ] : null,
                'negotiation_controls' => [
                    'can_send_offer' => $canSendOffer,
                    'can_cancel_negotiation' => $canCancelNegotiation,
                ],
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

    /**
     * Cancel a supply job directly (no active negotiation).
     * Only the provider or the requester of the rental job can cancel.
     */
    public function cancelSupplyJob(Request $request, $supplyJobId)
    {
        $user = auth('api')->user();

        try {
            $supplyJob = SupplyJob::with([
                'provider.defaultContact.profile',
                'rentalJob.user.company.defaultContact.profile'
            ])->findOrFail($supplyJobId);

            Log::info('Cancel Supply Job - Supply Job Found', ['supply_job_id' => $supplyJobId]);
            Log::info($supplyJob->toArray());

            DB::transaction(function () use ($supplyJob, $request, $user) {

                // Cancel the supply job
                // Cancel the supply job
                $supplyJob->status = 'Cancelled';
                $supplyJob->notes = $request->reason;
                $supplyJob->cancelled_by = $user->id;
                $supplyJob->save();

                // Also cancel related job offers
                JobOffer::where('supply_job_id', $supplyJob->id)->update(['status' => 'cancelled']);
            });

            // ===================================================
            // EMAIL NOTIFICATION TO RENTAL JOB REQUESTER COMPANY
            // ===================================================
            $rentalJob = $supplyJob->rentalJob;
            $requesterCompany = $rentalJob?->user?->company;
            $requesterEmail = $requesterCompany?->defaultContact?->profile?->email;

            //Currency
            $currencySymbol = '';
            if ($supplyJob->provider->currency_id) {
                $currency = Currency::find($supplyJob->provider->currency_id);
                $currencySymbol = $currency ? $currency->symbol : '₹';
            }

            //Get products offered in the supply job
            $offerProducts = SupplyJobProduct::with(['product.getEquipment'])
                ->where('supply_job_id', $supplyJob->id)
                ->get()
                ->map(function ($item) {
                    return [
                        'psm_code' => $item->product->psm_code ?? '—',
                        'model' => $item->product->model ?? '—',
                        'software_code' => $item->product->getEquipment->software_code ?? '—',
                        'quantity' => $item->offered_quantity ?? $item->quantity ?? 0,
                        'price' => $item->price_per_unit ?? $item->price ?? 0,
                    ];
                })
                ->toArray();

            //Prepare email data
            $mailData = [
                'provider' => $supplyJob->provider->name ?? '-',
                'supply_job_name' => $rentalJob->name ?? '-',
                'status' => 'Cancelled',
                'reason' => $request->reason ?? null,
                'date' => now()->format('d M Y, h:i A'),
                'products' => $offerProducts,
                'currency' => $currencySymbol,
            ];

            //Log to verify
            Log::info('Cancel Supply Job - Recipient email', ['email' => $requesterEmail]);
            Log::info('Cancel Supply Job - Mail Data', $mailData);

            //Send email if requester contact exists
            if ($requesterEmail) {
                Mail::send('emails.supplyJobCancelled', $mailData, function ($message) use ($requesterEmail) {
                    $message->to($requesterEmail)
                        ->subject('Supply Job Cancelled - Pro Subrental Marketplace');
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Supply job cancelled successfully.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to cancel supply job.'], 500);
        }
    }

}
