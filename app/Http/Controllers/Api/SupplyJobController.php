<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SupplyJob;
use App\Models\RentalJob;
use App\Models\RentalJobProduct;
use App\Models\JobOffer;
use App\Models\Currency;
use App\Models\SupplyJobProduct;
use App\Models\JobRating;
use App\Models\JobRatingReply;
use App\Models\Company;
use App\Models\User;
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
            'status' => 'nullable|string|in:pending,negotiating,accepted,cancelled,closed,partially_accepted,completed,completed_pending_rating,rated',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $companyId = (int) $request->input('company_id');
        $status = $request->input('status');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        // $perPage = $request->input('per_page', 20); // default 20 per page

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
                'rentalJob:id,name,from_date,to_date,user_id',
                'rentalJob.user:id,company_id',
                'rentalJob.user.company:id,name',
                'jobRating',
                'ratingReply',
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
            //Pagination decision
            $perPage = $validated['per_page'] ?? null;

            if ($perPage) {
                $paginator = $query->paginate($perPage);
                $collection = $paginator->getCollection();
            } else {
                $collection = $query->get();
                $paginator = null;
            }

            //Transform data (works for both cases)
            $data = $collection->map(function (SupplyJob $job) {
                $row = [
                    'id' => $job->id,
                    'name' => $job->rentalJob?->name ?? '',
                    'rental_job_id' => $job->rentalJob?->id,
                    'start_date' => $job->rentalJob?->from_date,
                    'end_date' => $job->rentalJob?->to_date,
                    'status' => $job->status,
                    'products' => $job->products->map(function ($sp) {
                        $brand = $sp->product?->brand?->name ?? '';
                        $model = $sp->product?->model ?? '';

                        return [
                            'id' => $sp->product_id,
                            'name' => trim("{$brand} - {$model}", ' -'),
                        ];
                    })->values(),
                ];
                if ($job->rentalJob?->user?->company) {
                    $row['renter_company_name'] = $job->rentalJob->user->company->name;
                }
                if ($job->status === 'rated' && $job->jobRating?->rated_at) {
                    $jr = $job->jobRating;
                    $reply = $job->ratingReply;
                    $row['job_rating'] = [
                        'rating' => (int) $jr->rating,
                        'comment' => $jr->comment,
                        'rated_at' => $jr->rated_at->toIso8601String(),
                        'provider_reply' => $reply?->reply,
                        'provider_replied_at' => $reply?->replied_at?->toIso8601String(),
                    ];
                }
                return $row;
            })->values();

            //Response
            $response = [
                'success' => true,
                'data' => $data,
            ];

            //Pagination meta only when paginated
            if ($paginator) {
                $response['meta'] = [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ];
            }

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
                'jobRating',
                'ratingReply',
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

            $rentalJob = RentalJob::with(['user.company'])
                ->where('id', $supplyJob->rental_job_id)
                ->first();


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
            $canSendOffer = true;
            $canCancelNegotiation = true;
            $canHandshake = true;

            // If we have an offer, adjust permission
            if ($latestOffer) {

                // If latest offer is accepted/cancelled, disable everything
                if (in_array($latestOffer->status, ['accepted', 'cancelled'])) {
                    $canSendOffer = false;
                    $canCancelNegotiation = false;
                    $canHandshake = false;

                } else {
                    // Latest offer is pending
                    if ($latestOffer->sender_company_id == $loggedInCompany) {
                        // User sent last offer -> cannot send again
                        $canSendOffer = false;
                        $canCancelNegotiation = false;
                        $canHandshake = false;
                    } else {
                        // Other company sent the last offer -> can reply
                        $canSendOffer = true;
                        $canCancelNegotiation = true;
                        $canHandshake = true;
                    }
                }
            }


            $data = [
                'id' => $supplyJob->id,
                'name' => $supplyJob->rentalJob->name,
                'rental_job_id' => $supplyJob->rentalJob->id,
                'renter_company_name' => optional($rentalJob->user->company)->name,
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
                    'can_handshake' => $canHandshake,
                    'can_send_offer' => $canSendOffer,
                    'can_cancel_negotiation' => $canCancelNegotiation,
                ],
            ];

            if ($supplyJob->status === 'rated' && $supplyJob->jobRating?->rated_at) {
                $jobRating = $supplyJob->jobRating;
                $reply = $supplyJob->ratingReply;
                $data['job_rating'] = [
                    'rating' => (int) $jobRating->rating,
                    'comment' => $jobRating->comment,
                    'rated_at' => $jobRating->rated_at->toIso8601String(),
                    'provider_reply' => $reply?->reply,
                    'provider_replied_at' => $reply?->replied_at?->toIso8601String(),
                ];
            }

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
                $supplyJob->status = 'cancelled';
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

            // Build reason line (HTML or empty)
            $reasonDisplay = '';
            if (!empty($request->reason)) {
                $reasonDisplay = '<p><strong>Reason:</strong> ' . e($request->reason) . '</p>';
            }

            // Build products table HTML for DB template compatibility
            $productsSection = '';
            if (!empty($offerProducts)) {
                $grandTotal = 0;
                $currencySymbol = $currencySymbol ?: '';
                $rows = '';
                foreach ($offerProducts as $p) {
                    $qty = (int) ($p['quantity'] ?? 0);
                    $price = (float) ($p['price'] ?? 0);
                    $total = $qty * $price;
                    $grandTotal += $total;
                    $rows .= '<tr style="border-bottom: 1px solid #eee;">';
                    $rows .= '<td>' . e($p['psm_code'] ?? '—') . '</td>';
                    $rows .= '<td>' . e($p['model'] ?? '-') . '</td>';
                    $rows .= '<td>' . e($p['software_code'] ?? '—') . '</td>';
                    $rows .= '<td>' . $qty . '</td>';
                    $rows .= '<td>' . $currencySymbol . number_format($price, 2) . '</td>';
                    $rows .= '<td>' . $currencySymbol . number_format($total, 2) . '</td>';
                    $rows .= '</tr>';
                }
                $productsSection = '<h3 style="color: #1a73e8; margin-top: 25px;">Cancelled Equipment Details</h3>'
                    . '<table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse; margin-top: 10px; font-size: 14px;">'
                    . '<thead style="background-color: #f0f0f0; border-bottom: 2px solid #ddd;">'
                    . '<tr><th align="left">PSM Code</th><th align="left">Model</th><th align="left">Software Code</th><th align="left">Qty</th><th align="left">Price</th><th align="left">Total Price</th></tr>'
                    . '</thead><tbody>' . $rows
                    . '<tr style="font-weight: bold; background-color: #fafafa;"><td colspan="5" align="right">Grand Total</td><td>' . $currencySymbol . number_format($grandTotal, 2) . '</td></tr>'
                    . '</tbody></table>';
            }

            $mailData = [
                'provider' => $supplyJob->provider->name ?? '-',
                'supply_job_name' => $rentalJob->name ?? '-',
                'status' => 'Cancelled',
                'reason_display' => $reasonDisplay,
                'date' => now()->format('d M Y, h:i A'),
                'products_section' => $productsSection,
            ];

            //Log to verify
            Log::info('Cancel Supply Job - Recipient email', ['email' => $requesterEmail]);
            Log::info('Cancel Supply Job - Mail Data', $mailData);

            //Send email if requester contact exists
            if ($requesterEmail) {
                \App\Helpers\EmailHelper::send('supplyJobCancelled', $mailData, function ($message) use ($requesterEmail) {
                    $message->to($requesterEmail)
                        ->from(config('mail.from.address'), config('mail.from.name'));
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

    /**
     * Provider marks a supply job as completed.
     * Updates supply job and linked rental job to completed_pending_rating.
     * Triggers rating request to renter.
     */
    public function complete(Request $request, int $id)
    {
        $user = Auth::user();

        try {
            $supplyJob = SupplyJob::with('rentalJob')->findOrFail($id);

            if ((int) $user->company_id !== (int) $supplyJob->provider_id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized for this supply job.'
                ], 403);
            }

            if (!in_array($supplyJob->status, ['accepted', 'partially_accepted'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only jobs in accepted or partially accepted status can be marked as completed.'
                ], 400);
            }

            DB::transaction(function () use ($supplyJob) {
                $supplyJob->update([
                    'status' => 'completed_pending_rating',
                    'completed_at' => now(),
                ]);

                if ($supplyJob->rentalJob) {
                    $rentalJob = $supplyJob->rentalJob;
                    // Rental job = completed_pending_rating only when ALL accepted providers have marked completed
                    $anyStillPending = SupplyJob::where('rental_job_id', $rentalJob->id)
                        ->whereIn('status', ['accepted', 'partially_accepted'])
                        ->exists();
                    $rentalJob->update([
                        'status' => $anyStillPending ? 'partially_accepted' : 'completed_pending_rating',
                    ]);
                }
            });

            $supplyJob->refresh();
            $supplyJob->load(['rentalJob:id,name,from_date,to_date,user_id', 'providerCompany:id,name']);

            $emails = [];
            $rentalJob = $supplyJob->rentalJob;
            if ($rentalJob) {
                $rentalUser = User::with('profile')->find($rentalJob->user_id);
                if ($rentalUser?->profile?->email) {
                    $emails[] = $rentalUser->profile->email;
                }
                $companyId = $rentalUser?->company_id ?? null;
                if ($companyId) {
                    $requesterCompany = Company::with('defaultContact.profile')->find($companyId);
                    if ($requesterCompany?->defaultContact?->profile?->email) {
                        $emails[] = $requesterCompany->defaultContact->profile->email;
                    }
                }
                $emails = array_unique(array_filter($emails));
                foreach ($emails as $email) {
                    \App\Helpers\EmailHelper::send('jobRatingRequest', [
                        'rental_job_name' => $rentalJob->name,
                        'provider_name' => $supplyJob->providerCompany->name ?? 'Provider',
                        'supply_job_name' => $supplyJob->name ?? '',
                        'provider_company_name' => $supplyJob->providerCompany->name ?? 'Provider',
                    ], function ($message) use ($email) {
                        $message->to($email)
                            ->from(config('mail.from.address'), config('mail.from.name'));
                    });
                }
            }

            $data = [
                'id' => $supplyJob->id,
                'status' => $supplyJob->status,
                'name' => $supplyJob->rentalJob?->name ?? '',
                'rental_job_id' => $supplyJob->rental_job_id,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Job marked as completed. Renter will be asked to rate.',
                'data' => $data,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to mark job as completed.'], 500);
        }
    }

    /**
     * Renter rates this provider (supply job). Delegates to job-level rating with same logic.
     * Auth: current user's company must be the renter of the rental job that owns this supply job.
     */
    public function rate(Request $request, int $id)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        try {
            $supplyJob = SupplyJob::with('rentalJob.user')->findOrFail($id);
            $rentalJob = $supplyJob->rentalJob;
            if (!$rentalJob) {
                return response()->json(['success' => false, 'message' => 'Rental job not found.'], 404);
            }

            if ((int) $rentalJob->user->company_id !== (int) $user->company_id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only the renter can rate this job.',
                ], 403);
            }

            if ($rentalJob->status !== 'completed_pending_rating') {
                return response()->json([
                    'success' => false,
                    'message' => 'Job must be in completed (pending rating) status to submit a rating.',
                ], 400);
            }

            DB::transaction(function () use ($supplyJob, $rentalJob, $validated) {
                JobRating::updateOrCreate(
                    ['supply_job_id' => $supplyJob->id],
                    [
                        'rental_job_id' => $rentalJob->id,
                        'rating' => $validated['rating'],
                        'comment' => $validated['comment'] ?? null,
                        'rated_at' => now(),
                        'skipped_at' => null,
                    ]
                );
                $supplyJob->update(['status' => 'rated']);
                $pendingCount = SupplyJob::where('rental_job_id', $rentalJob->id)
                    ->where('status', 'completed_pending_rating')
                    ->count();
                if ($pendingCount === 0) {
                    $rentalJob->update(['status' => 'rated']);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Rating submitted successfully',
                'data' => [],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to submit rating.'], 500);
        }
    }

    /**
     * Renter skips rating for this provider (supply job). Delegates to job-level skip; marks job as rated.
     * Auth: current user's company must be the renter of the rental job that owns this supply job.
     */
    public function rateSkip(Request $request, int $id)
    {
        $user = Auth::user();

        try {
            $supplyJob = SupplyJob::with('rentalJob.user')->findOrFail($id);
            $rentalJob = $supplyJob->rentalJob;
            if (!$rentalJob) {
                return response()->json(['success' => false, 'message' => 'Rental job not found.'], 404);
            }

            if ((int) $rentalJob->user->company_id !== (int) $user->company_id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only the renter can skip rating for this job.',
                ], 403);
            }

            if ($rentalJob->status !== 'completed_pending_rating') {
                return response()->json([
                    'success' => false,
                    'message' => 'Job must be in completed (pending rating) status to skip rating.',
                ], 400);
            }

            DB::transaction(function () use ($supplyJob, $rentalJob) {
                JobRating::updateOrCreate(
                    ['supply_job_id' => $supplyJob->id],
                    [
                        'rental_job_id' => $rentalJob->id,
                        'skipped_at' => now(),
                    ]
                );
                $supplyJob->update(['status' => 'rated']);
                $pendingCount = SupplyJob::where('rental_job_id', $rentalJob->id)
                    ->where('status', 'completed_pending_rating')
                    ->count();
                if ($pendingCount === 0) {
                    $rentalJob->update(['status' => 'rated']);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Rating skipped.',
                'data' => [],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to skip rating.'], 500);
        }
    }

    /**
     * Provider replies to the renter's rating comment.
     */
    public function ratingReply(Request $request, int $id)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'reply' => 'required|string|max:2000',
        ]);

        $reply = trim($validated['reply']);
        if ($reply === '') {
            return response()->json([
                'success' => false,
                'message' => 'Reply cannot be empty.'
            ], 422);
        }

        try {
            $supplyJob = SupplyJob::with(['jobRating'])->findOrFail($id);

            if ((int) $user->company_id !== (int) $supplyJob->provider_id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized for this supply job.'
                ], 403);
            }

            if ($supplyJob->status !== 'rated') {
                return response()->json([
                    'success' => false,
                    'message' => 'Job must be in rated status to submit a reply.'
                ], 400);
            }

            $jobRating = $supplyJob->jobRating;
            if (!$jobRating || !$jobRating->rated_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'No rating exists for this job.'
                ], 400);
            }

            $replyModel = JobRatingReply::updateOrCreate(
                ['supply_job_id' => $supplyJob->id],
                [
                    'job_rating_id' => $jobRating->id,
                    'reply' => $reply,
                    'replied_at' => now(),
                ]
            );

            $jobRatingArray = [
                'rating' => (int) $jobRating->rating,
                'comment' => $jobRating->comment,
                'rated_at' => $jobRating->rated_at->toIso8601String(),
                'provider_reply' => $replyModel->reply,
                'provider_replied_at' => $replyModel->replied_at->toIso8601String(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Reply submitted successfully',
                'data' => ['job_rating' => $jobRatingArray],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to submit reply.'], 500);
        }
    }

}
