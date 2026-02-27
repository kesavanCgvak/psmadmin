<?Php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{
    RentalJob,
    SupplyJob,
    JobOffer,
    Company,
    Currency,
    User,
    SupplyJobProduct
};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\JobOfferNotificationMail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Log;

class JobNegotiationController extends Controller
{
    /**
     * Send or respond to an offer between User ↔ Provider.
     * Handles both directions:
     * - User → Provider
     * - Provider → User
     *
     * Request Body:
     * {
     *   "target_company_id": int,   // company you’re sending the offer to
     *   "amount": float,            // proposed total amount
     *   "message": string|null      // optional message
     * }
     */
    public function sendOffer(Request $request, int $jobId)
    {
        try {
            // 1. AUTH
            if (!$user = auth('api')->user()) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // 2. VALIDATE INPUT
            $validator = Validator::make($request->all(), [
                'target_company_id' => 'required|integer|exists:companies,id',
                'amount' => 'required|numeric|min:0',
                'message' => 'nullable|string|max:2000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // 3. LOAD RENTAL JOB
            $rentalJob = RentalJob::with('supplyJobs')->find($jobId);
            if (!$rentalJob) {
                return response()->json(['success' => false, 'message' => 'Rental job not found'], 404);
            }

            $senderCompanyId = $user->company_id;
            $receiverCompanyId = $data['target_company_id'];

            if ($senderCompanyId == $receiverCompanyId) {
                return response()->json(['success' => false, 'message' => 'Cannot send offer to your own company'], 400);
            }

            // 4. DETERMINE SUPPLY JOB (NO DB UPDATE YET)
            if ($user->id === $rentalJob->user_id) {
                $supplyJob = $rentalJob->supplyJobs()->where('provider_id', $receiverCompanyId)->first();
            } else {
                $supplyJob = $rentalJob->supplyJobs()->where('provider_id', $senderCompanyId)->first();
            }

            if (!$supplyJob) {
                return response()->json(['success' => false, 'message' => 'Supply job not found'], 400);
            }

            // 5. BUSINESS VALIDATIONS BEFORE DB UPDATES

            // a) Prevent offer spam
            $lastOffer = JobOffer::where('rental_job_id', $rentalJob->id)
                ->where('supply_job_id', $supplyJob->id)
                ->orderByDesc('version')
                ->first();

            if ($lastOffer && $lastOffer->last_offer_by == $senderCompanyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already sent the last offer. Wait for the other party.'
                ], 403);
            }

            // b) Rental job must be open to negotiation
            if (!in_array($rentalJob->status, ['open', 'in_negotiation', 'reviewing'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Job cannot enter negotiation in current status.'
                ], 400);
            }

            // c) Supply job status check
            if (!in_array($supplyJob->status, ['pending', 'negotiating'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supply job not eligible for negotiation.'
                ], 400);
            }

            // -----------------------------
            // ALL CHECKS PASSED
            // SAFE TO UPDATE DATABASE
            // -----------------------------
            DB::beginTransaction();

            // 6. Update statuses
            $rentalJob->update(['status' => 'in_negotiation']);
            $supplyJob->update(['status' => 'negotiating']);

            $supplyJob->update([
                'handshake_status' => $user->account_type === 'provider'
                    ? 'pending_user'
                    : 'pending_provider'
            ]);

            // 7. Create new offer
            $nextVersion = ($lastOffer->version ?? 0) + 1;

            $offer = JobOffer::create([
                'rental_job_id' => $rentalJob->id,
                'supply_job_id' => $supplyJob->id,
                'sender_company_id' => $senderCompanyId,
                'receiver_company_id' => $receiverCompanyId,
                'version' => $nextVersion,
                'total_price' => $data['amount'],
                'currency_id' => $user->company->currency_id,
                'status' => 'pending',
                'last_offer_by' => $senderCompanyId,
            ]);

            if (!empty($data['message'])) {
                $supplyJob->comments()->create([
                    'rental_job_id' => $rentalJob->id,
                    'sender_id' => $user->id,
                    'message' => $data['message'],
                ]);
            }

            DB::commit();

            // ------------------------------
            // 8. Email Notification Setup
            // ------------------------------
            $senderCompany = $user->company;
            $receiverCompany = Company::with('defaultContact.profile')->find($receiverCompanyId);
            $receiverContact = $receiverCompany->defaultContact ?? null;

            // Currency
            $currencySymbol = '';
            if ($senderCompany && $senderCompany->currency_id) {
                $currency = Currency::find($senderCompany->currency_id);
                $currencySymbol = $currency ? $currency->symbol : '';
            }

            // Supply job product list
            $offerProducts = SupplyJobProduct::with(['product.getEquipment'])
                ->where('supply_job_id', $supplyJob->id)
                ->get()
                ->map(function ($item) {
                    return [
                        'psm_code' => $item->product->psm_code ?? '—',
                        'model' => $item->product->model ?? '—',
                        'software_code' => $item->product->getEquipment->software_code ?? '—',
                        'quantity' => $item->offered_quantity ?? 0,
                        'price' => $item->price_per_unit ?? 0,
                    ];
                })
                ->toArray();

            $mailContent = [
                'sender_company_name' => $senderCompany->name,
                'receiver_contact_name' => $receiverContact?->profile?->first_name ?? 'there',
                'version' => $offer->version,
                'total_price' => $offer->total_price,
                'currency' => $currencySymbol,
                'status' => ucfirst($offer->status),
                'products' => $offerProducts,
            ];

            // Collect receiver emails
            $emails = [];

            if ($receiverContact?->profile?->email) {
                $emails[] = $receiverContact->profile->email;
            }

            $receiverUser = User::where('company_id', $receiverCompanyId)
                ->whereHas('profile', fn($q) => $q->whereNotNull('email'))
                ->first();

            if ($receiverUser) {
                $emails[] = $receiverUser->profile->email;
            }

            $emails = array_unique(array_filter($emails));

            // ------------------------------
            // 🔹 Send Email (Using Mailable)
            // ------------------------------
            foreach ($emails as $email) {
                Mail::to($email)->send(
                    new JobOfferNotificationMail($mailContent)
                );
            }


            return response()->json([
                'success' => true,
                'message' => 'Offer sent successfully.',
                'data' => [
                    'offer_id' => $offer->id,
                    'version' => $offer->version,
                    'total_price' => $offer->total_price,
                ],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['success' => false, 'message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Summary of handshake
     * @param \Illuminate\Http\Request $request
     * @param int $offerId
     * @return \Illuminate\Http\JsonResponse
     */
    public function handshake(Request $request, int $offerId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        try {
            $offer = JobOffer::with(['rentalJob.products', 'supplyJob.products'])->findOrFail($offerId);

            $companyId = $user->company_id;

            // Ensure only the opposite party can handshake
            if ($offer->last_offer_by == $companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot handshake your own offer. Please wait for the other party to respond.'
                ], 403);
            }

            // Ensure offer is still pending
            if ($offer->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'This offer has already been accepted or cancelled.'
                ], 422);
            }

            $rentalJob = $offer->rentalJob;
            $supplyJob = $offer->supplyJob;

            $remainingQty = 0;

            DB::transaction(function () use ($offer, $supplyJob, $rentalJob, &$remainingQty) {

                // Accept the offer & supply job ---
                $offer->status = 'accepted';
                $offer->save();

                $supplyJob->status = 'accepted';
                $supplyJob->accepted_by = $offer->receiver_company_id;
                $supplyJob->accepted_price = $offer->total_price;
                $supplyJob->handshake_status = 'accepted';
                $supplyJob->save();

                // Initialize totals ---
                $totalRequestedQty = $rentalJob->products->sum('requested_quantity');
                $totalFulfilledBefore = $rentalJob->products->sum('fulfilled_quantity');
                $fulfilledThisOffer = 0;

                //  Product-level fulfillment ---
                foreach ($supplyJob->products as $supplyProduct) {
                    $rentalProduct = $rentalJob->products
                        ->where('product_id', $supplyProduct->product_id)
                        ->first();

                    if ($rentalProduct) {
                        $acceptedQty = min(
                            $supplyProduct->offered_quantity ?? 0,
                            ($rentalProduct->requested_quantity - ($rentalProduct->fulfilled_quantity ?? 0))
                        );

                        if ($acceptedQty > 0) {
                            // Update rental job product
                            $rentalProduct->fulfilled_quantity = ($rentalProduct->fulfilled_quantity ?? 0) + $acceptedQty;
                            $fulfilledThisOffer += $acceptedQty;

                            if ($rentalProduct->fulfilled_quantity >= $rentalProduct->requested_quantity) {
                                $rentalProduct->status = 'fulfilled';
                            } else {
                                $rentalProduct->status = 'partially_fulfilled';
                            }

                            $rentalProduct->save();

                            //update supply job  fulfilled quantity
                            $supplyJob->fulfilled_quantity = ($supplyJob->fulfilled_quantity ?? 0) + $acceptedQty;
                            $supplyJob->save();

                            // Update supply job product
                            $supplyProduct->accepted_quantity = $acceptedQty;
                            $supplyProduct->save();
                        }
                    }
                }

                // Calculate job-level fulfillment ---
                $totalFulfilledNow = $totalFulfilledBefore + $fulfilledThisOffer;

                if ($totalFulfilledNow >= $totalRequestedQty) {
                    // Fully fulfilled → mark as completed
                    $rentalJob->status = 'completed';
                    $rentalJob->fulfilled_quantity = $totalRequestedQty;
                    $remainingQty = 0;

                    // Cancel all other open supply jobs
                    SupplyJob::where('rental_job_id', $rentalJob->id)
                        ->where('id', '!=', $supplyJob->id)
                        ->whereNotIn('status', ['accepted', 'completed'])
                        ->update(['status' => 'cancelled']);
                } else {
                    // Partially fulfilled
                    $rentalJob->status = 'partially_accepted';
                    $rentalJob->fulfilled_quantity = $totalFulfilledNow;
                    $remainingQty = $totalRequestedQty - $totalFulfilledNow;
                }

                $rentalJob->save();
            });

            // ==========================
            //  EMAIL NOTIFICATIONS
            // ==========================

            $currencySymbol = '';
            if ($user->company && $user->company->currency_id) {
                $currency = Currency::find($user->company->currency_id);
                $currencySymbol = $currency ? $currency->symbol : '';
            }

            $senderCompany = $offer->senderCompany;
            $receiverCompany = $offer->receiverCompany;
            // ============================================
            // PRODUCT DETAILS FOR EMAIL (Handshake + Partial)
            // ============================================

            $handshakeProducts = $supplyJob->products()
                ->with(['product.getEquipment'])
                ->get()
                ->map(function ($item) {
                    return [
                        'psm_code' => $item->product->psm_code ?? '—',
                        'model' => $item->product->model ?? '—',
                        'software_code' => $item->product->getEquipment->software_code ?? '—',
                        'accepted_quantity' => $item->accepted_quantity ?? 0,
                        'price_per_unit' => $item->price_per_unit ?? 0,
                    ];
                })
                ->toArray();

            // Collect all recipient emails (sender + receiver)
            $emails = [];
            foreach ([$senderCompany, $receiverCompany] as $company) {
                if ($company && $company->defaultContact && $company->defaultContact->profile) {
                    $email = $company->defaultContact->profile->email;
                    if ($email)
                        $emails[] = $email;
                }
            }

            $emails = array_unique(array_filter($emails));

            // Handshake success mail
            $mailContent = [
                'offer_id' => $offer->id,
                'sender' => $senderCompany->name ?? 'A Company',
                'receiver' => $receiverCompany->name ?? 'A Company',
                'rental_job_name' => $rentalJob->name ?? '',
                'amount' => number_format($offer->total_price, 2),
                'currency_symbol' => $currencySymbol,
                'version' => $offer->version,
                'fulfilled_quantity' => $rentalJob->fulfilled_quantity,
                'remaining_quantity' => $remainingQty,
                'date' => now()->format('d M Y, h:i A'),
                'products' => $handshakeProducts,
            ];

            foreach ($emails as $email) {
                Mail::send('emails.jobHandshakeAccepted', $mailContent, function ($message) use ($email) {
                    $message->to($email)
                        ->subject('Offer Accepted - Pro Subrental Marketplace')
                        ->from(config('mail.from.address'), config('mail.from.name'));
                });
            }

            // If job is completed → notify other suppliers
            if ($rentalJob->status === 'completed') {
                $otherSuppliers = SupplyJob::with('provider.defaultContact.profile')
                    ->where('rental_job_id', $rentalJob->id)
                    ->where('id', '!=', $supplyJob->id)
                    ->get();

                foreach ($otherSuppliers as $other) {
                    // $email = optional($other->provider->defaultContact->profile)->email;
                    $email = data_get($other, 'provider.defaultContact.profile.email');
                    if ($email) {
                        $cancelMail = [
                            'rental_job_name' => $rentalJob->name,
                            'fulfilled_quantity' => $rentalJob->fulfilled_quantity,
                            'date' => now()->format('d M Y, h:i A'),
                        ];

                        Mail::send('emails.jobAutoCancelled', $cancelMail, function ($message) use ($email) {
                            $message->to($email)
                                ->subject('Rental Request Closed - Pro Subrental Marketplace')
                                ->from(config('mail.from.address'), config('mail.from.name'));
                        });
                    }
                }
            }

            // Remaining products details for partial fulfillment email
            $remainingProducts = $rentalJob->products()
                ->with(['product.getEquipment'])
                ->get()
                ->map(function ($item) {
                    $remaining = ($item->requested_quantity - ($item->fulfilled_quantity ?? 0));

                    return [
                        'psm_code' => $item->product->psm_code ?? '—',
                        'model' => $item->product->model ?? '—',
                        'software_code' => $item->product->getEquipment->software_code ?? '—',
                        'requested_quantity' => $item->requested_quantity,
                        'fulfilled_quantity' => $item->fulfilled_quantity ?? 0,
                        'remaining_quantity' => max($remaining, 0),
                    ];
                })
                ->filter(fn($p) => $p['remaining_quantity'] > 0)
                ->values()
                ->toArray();

            // If partially fulfilled → notify open suppliers
            if ($rentalJob->status === 'partially_accepted') {
                $openSuppliers = SupplyJob::with('provider.defaultContact.profile')
                    ->where('rental_job_id', $rentalJob->id)
                    ->where('status', 'negotiating')
                    ->get();

                foreach ($openSuppliers as $open) {
                    // $email = optional($open->provider->defaultContact->profile)->email;
                    $email = data_get($open, 'provider.defaultContact.profile.email');

                    if ($email) {
                        $partialMail = [
                            'rental_job_name' => $rentalJob->name,
                            'remaining_quantity' => $remainingQty,
                            'products' => $remainingProducts,
                            'currency' => $currencySymbol,
                            'date' => now()->format('d M Y, h:i A'),
                        ];

                        Mail::send('emails.jobPartialFulfilled', $partialMail, function ($message) use ($email) {
                            $message->to($email)
                                ->subject('Updated Availability - Pro Subrental Marketplace')
                                ->from(config('mail.from.address'), config('mail.from.name'));
                        });
                    }
                }
            }

            // --- Final response ---
            return response()->json([
                'success' => true,
                'message' => 'Handshake successful.',
                'data' => [
                    'rental_job_status' => $rentalJob->status,
                    'fulfilled_quantity' => $rentalJob->fulfilled_quantity,
                    'remaining_quantity' => $remainingQty,
                    'currency_symbol' => $currencySymbol,
                    'accepted_amount' => $offer->total_price,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Offer not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to handshake.'], 500);
        }
    }

    /**
     * Cancel negotiation by receiver of the last offer.
     */
    public function cancelNegotiation(Request $request, int $offerId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        try {
            $offer = JobOffer::with(['rentalJob', 'supplyJob'])->findOrFail($offerId);
            $companyId = $user->company_id;

            // Only the receiver of the last offer can cancel
            if ($offer->last_offer_by == $companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot cancel your own offer. Please wait for the other party to respond.'
                ], 403);
            }

            // Only pending offers can be cancelled
            if ($offer->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'This offer has already been accepted or cancelled.'
                ], 422);
            }

            DB::transaction(function () use ($offer, $request, $user) {
                // Cancel this offer
                $offer->status = 'cancelled';
                $offer->save();

                // Cancel its supply job
                $supplyJob = $offer->supplyJob;
                if ($supplyJob) {
                    $supplyJob->status = 'cancelled';
                    $supplyJob->handshake_status = 'cancelled';
                    $supplyJob->notes = $request->reason;
                    $supplyJob->cancelled_by = $user->id;
                    $supplyJob->save();
                }

                // Re-check all supply jobs under the same rental job
                $rentalJob = $offer->rentalJob;
                if ($rentalJob) {
                    $totalJobs = $rentalJob->supplyJobs()->count();
                    $cancelledJobs = $rentalJob->supplyJobs()->where('status', 'cancelled')->count();

                    if ($totalJobs > 0 && $totalJobs === $cancelledJobs) {
                        // All cancelled → mark rental job as cancelled
                        $rentalJob->status = 'cancelled';
                    }
                    // else {
                    //     // Some still negotiating → keep in negotiation
                    //     $rentalJob->status = 'in_negotiation';
                    // }

                    $rentalJob->save();
                }
            });

            // ==========================
            // EMAIL NOTIFICATIONS
            // ==========================
            $receiverCompany = $offer->receiverCompany;
            $senderCompany = $offer->senderCompany;

            // Currency symbol
            $currencySymbol = '';
            if ($senderCompany && $senderCompany->currency_id) {
                $currency = Currency::find($senderCompany->currency_id);
                $currencySymbol = $currency ? $currency->symbol : '';
            }

            $emails = [];

            foreach ([$senderCompany, $receiverCompany] as $company) {
                $email = data_get($company, 'defaultContact.profile.email');
                if ($email)
                    $emails[] = $email;
            }

            $emails = array_unique(array_filter($emails));

            // ===============================
            // FETCH PRODUCT DETAILS FOR EMAIL
            // ===============================
            $productDetails = [];

            if ($offer->supplyJob) {
                $productDetails = SupplyJobProduct::with(['product.equipments'])
                    ->where('supply_job_id', $offer->supply_job_id)
                    ->get()
                    ->map(function ($item) {

                        $equipment = $item->product->equipments->first();

                        return [
                            'psm_code' => $item->product->psm_code ?? '-',
                            'model' => $item->product->model ?? '-',
                            'software_code' => $equipment->software_code ?? '-',
                            'quantity' => $item->offered_quantity ?? 0,
                            'price' => $item->price_per_unit ?? 0,
                            'total_price' => ($item->offered_quantity ?? 0) * ($item->price_per_unit ?? 0),
                        ];
                    })
                    ->toArray();
            }

            $mailContent = [
                'offer_id' => $offer->id,
                'sender' => $senderCompany->name ?? 'A Company',
                'receiver' => $receiverCompany->name ?? 'A Company',
                'rental_job_name' => $offer->rentalJob->name ?? '',
                'version' => $offer->version,
                'date' => now()->format('d M Y, h:i A'),
                'products' => $productDetails,
                'reason' => $request->reason ?? 'No reason provided.',
                'total_price' => $offer->total_price,
                'currency_symbol' => $currencySymbol,
            ];

            foreach ($emails as $email) {
                Mail::send('emails.jobNegotiationCancelled', $mailContent, function ($message) use ($email) {
                    $message->to($email)
                        ->subject('Negotiation Cancelled - Pro Subrental Marketplace')
                        ->from(config('mail.from.address'), config('mail.from.name'));
                });
            }

            // If rental job is fully cancelled, notify all involved suppliers
            $rentalJob = $offer->rentalJob;
            // if ($rentalJob && $rentalJob->status === 'cancelled') {
            //     $allSuppliers = SupplyJob::with('provider.defaultContact.profile')
            //         ->where('rental_job_id', $rentalJob->id)
            //         ->get();

            //     foreach ($allSuppliers as $supplier) {
            //         $email = data_get($supplier, 'provider.defaultContact.profile.email');
            //         if ($email) {
            //             $cancelMail = [
            //                 'rental_job_name' => $rentalJob->name,
            //                 'date' => now()->format('d M Y, h:i A'),
            //             ];

            //             Mail::send('emails.jobRequestCancelled', $cancelMail, function ($message) use ($email) {
            //                 $message->to($email)
            //                     ->subject('Rental Request Cancelled - Pro Subrental Marketplace')
            //                     ->from(config('mail.from.address'), config('mail.from.name'));
            //             });
            //         }
            //     }
            // }

            return response()->json([
                'success' => true,
                'message' => 'Negotiation cancelled successfully.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Offer not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to cancel negotiation.'], 500);
        }
    }


}
