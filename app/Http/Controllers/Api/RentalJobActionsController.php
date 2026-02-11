<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RentalJob;
use App\Models\RentalJobProduct;
use App\Models\SupplyJobProduct;
use App\Models\SupplyJob;
use App\Models\Company;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\JobOffer;
use App\Models\JobRating;
use Illuminate\Support\Facades\Mail;
use App\Mail\RentalJobBasicsUpdated;
use App\Mail\RentalJobQuantityUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class RentalJobActionsController extends Controller
{
    /**
     * Update basics: name, delivery_address, from_date, to_date.
     * Only the job owner or admin can update.
     */
    public function updateBasics(Request $request, int $id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|min:3|max:255',
            'delivery_address' => 'sometimes|string|min:3|max:255',
            'from_date' => 'sometimes|date',
            'to_date' => 'sometimes|date|after_or_equal:from_date',
            'status' => 'prohibited',
        ]);

        try {
            $job = RentalJob::query()->findOrFail($id);

            // Permission check
            if ($job->user_id !== $user->id && !$user->is_admin) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            // Update the job
            $job->fill($data)->save();

            Log::info('UpdateBasics: Job updated. Notifying suppliers.', [
                'rental_job_id' => $job->id,
                'updated_fields' => array_keys($data)
            ]);

            // Trigger supplier notifications
            $this->notifySuppliersAboutUpdate($job);

            return response()->json(['success' => true, 'message' => 'Rental job updated.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Rental job not found.'], 404);
        } catch (\Throwable $e) {
            Log::error('UpdateBasics error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Update failed.'], 500);
        }
    }
    private function notifySuppliersAboutUpdate($rentalJob)
    {
        Log::info('NotifySuppliers: Started', [
            'rental_job_id' => $rentalJob->id
        ]);

        // Get supply jobs
        $supplyJobs = SupplyJob::where('rental_job_id', $rentalJob->id)->get();

        Log::info('NotifySuppliers: Supply jobs found', [
            'count' => $supplyJobs->count()
        ]);

        foreach ($supplyJobs as $supplyJob) {

            Log::info('NotifySuppliers: Processing supply job', [
                'supply_job_id' => $supplyJob->id,
                'provider_id' => $supplyJob->provider_id
            ]);

            // Step 1: Provider company
            $company = Company::find($supplyJob->provider_id);

            if (!$company) {
                Log::warning('NotifySuppliers: Company not found', [
                    'provider_id' => $supplyJob->provider_id
                ]);
                continue;
            }

            // Step 2: Default contact user
            $contactUser = User::find($company->default_contact_id);

            if (!$contactUser) {
                Log::warning('NotifySuppliers: Default contact user not found', [
                    'default_contact' => $company->default_contact_id
                ]);
                continue;
            }

            // Step 3: Profile + email
            $profile = UserProfile::where('user_id', $contactUser->id)->first();

            if (!$profile || !$profile->email) {
                Log::warning('NotifySuppliers: Email missing for user', [
                    'user_id' => $contactUser->id
                ]);
                continue;
            }

            // Prepare receiver details
            $receiver = (object) [
                'contact_name' => $contactUser->name ?? 'there',
                'email' => $profile->email,
                'company_name' => $company->name ?? '-',
            ];

            Log::info('NotifySuppliers: Sending email', [
                'email' => $receiver->email,
                'contact_name' => $receiver->contact_name
            ]);

            // Step 4: Send email (safe)
            try {
                Mail::to($receiver->email)->send(
                    new RentalJobBasicsUpdated($rentalJob, $supplyJob, $receiver)
                );

                Log::info('NotifySuppliers: Email successfully sent', [
                    'email' => $receiver->email
                ]);

            } catch (\Throwable $e) {
                Log::error('NotifySuppliers: Failed to send email', [
                    'email' => $receiver->email,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Update requested quantities for a product for a specific supplier.
     * Only owner or admin.
     */
    public function updateRequestedQuantities(Request $request, int $id)
    {
        /**
         * 1 — AUTH CHECK (USER TOKEN EXPIRED OR INVALID)
         */
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Tymon\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Session expired. Please login again.'
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 401);
        }

        /**
         * 2 — REQUEST VALIDATION
         */
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct',
            'items.*.requested_quantity' => 'required|integer|min:0',
            'items.*.supply_job_id' => 'required|integer',
        ]);

        try {
            /**
             * 3 — RENTAL JOB VALIDATION
             */
            $job = RentalJob::with('products.product')->find($id);

            if (!$job) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rental job not found.'
                ], 404);
            }

            if (!in_array($job->status, ['open','in_negotiation','partially_accepted'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Update not allowed. Rental job is {$job->status}."
                ], 400);
            }

            // permission check
            if ($job->user_id !== $user->id && !$user->is_admin) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            /**
             * 4 — SUPPLY JOB VALIDATION (FROM FIRST ITEM)
             */
            $supplyJobId = $validated['items'][0]['supply_job_id'];
            $supplyJob = SupplyJob::find($supplyJobId);

            if (!$supplyJob) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supply job not found.'
                ], 404);
            }

            if ($supplyJob->rental_job_id != $job->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supply job does not belong to this rental job.'
                ], 400);
            }

            if (!in_array($supplyJob->status, ['pending','negotiating','partially_accepted'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Supply job is {$supplyJob->status}. Updates are not allowed."
                ], 400);
            }

            /**
             * 5 — PROCESS QUANTITY UPDATES
             */
            $updatedItems = [];

            DB::transaction(function () use ($job, $validated, &$updatedItems) {

                foreach ($validated['items'] as $item) {

                    $productId = $item['product_id'];
                    $newQtySupplier = $item['requested_quantity'];
                    $supplyJobId = $item['supply_job_id'];

                    // Find supplier product
                    $supplierProduct = SupplyJobProduct::where('supply_job_id', $supplyJobId)
                        ->where('product_id', $productId)
                        ->first();

                    if (!$supplierProduct) {
                        continue;
                    }

                    $oldQtySupplier = $supplierProduct->required_quantity;

                    // Calculate difference
                    $difference = $newQtySupplier - $oldQtySupplier;

                    // Update supplier quantity
                    $supplierProduct->required_quantity = $newQtySupplier;
                    $supplierProduct->save();

                    // Update rental job requested quantity
                    $rjp = $job->products()
                        ->where('product_id', $productId)
                        ->first();

                    if ($rjp) {
                        $rjp->requested_quantity += $difference;
                        $rjp->save();
                    }

                    $product = $rjp->product ?? null;

                    $updatedItems[] = [
                        'product_id' => $productId,
                        'psm_code' => $product->psm_code ?? '',
                        'model' => $product->model ?? '',
                        'software_code' => $product->software_code ?? '',
                        'old_qty' => $oldQtySupplier,
                        'new_qty' => $newQtySupplier,
                        'price' => $supplierProduct->price_per_unit,
                        'total' => $supplierProduct->price_per_unit * $newQtySupplier,
                        'supply_job_id' => $supplyJobId
                    ];
                }
            });

            Log::info('UpdateRequestedQuantities: Quantities updated.', [
                'rental_job_id' => $job->id,
                'supply_job' => $supplyJobId,
                'updated_items' => $updatedItems
            ]);

            if (!empty($updatedItems)) {
                $this->notifySpecificSupplier($job, $updatedItems);
            }

            return response()->json([
                'success' => true,
                'message' => 'Requested quantities updated.',
                'updated_items' => $updatedItems
            ]);

        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Update failed. Something went wrong.'
            ], 500);
        }
    }

    /**
     * Notify ONLY the supplier of the selected supply job.
     */
    private function notifySpecificSupplier($rentalJob, array $updatedItems)
    {
        if (empty($updatedItems)) {
            Log::warning("notifySpecificSupplier called with EMPTY updatedItems", [
                'job_id' => $rentalJob->id
            ]);
            return;
        }

        // All updated items belong to same supply_job_id (frontend logic)
        $supplyJobId = $updatedItems[0]['supply_job_id'];

        Log::info("notifySpecificSupplier: Start", [
            'rental_job_id' => $rentalJob->id,
            'supply_job_id' => $supplyJobId,
            'updated_items' => $updatedItems
        ]);

        $supplyJob = SupplyJob::with(['products'])
            ->find($supplyJobId);

        if (!$supplyJob) {
            Log::error("notifySpecificSupplier FAILED: SupplyJob not found", [
                'supply_job_id' => $supplyJobId
            ]);
            return;
        }

        $company = Company::find($supplyJob->provider_id);
        if (!$company) {
            Log::error("notifySpecificSupplier FAILED: Supplier company not found", [
                'provider_id' => $supplyJob->provider_id
            ]);
            return;
        }

        $contactUser = User::find($company->default_contact_id);
        if (!$contactUser) {
            Log::error("notifySpecificSupplier FAILED: Contact user missing", [
                'contact_user_id' => $company->default_contact_id
            ]);
            return;
        }

        $profile = UserProfile::where('user_id', $contactUser->id)->first();
        if (!$profile || !$profile->email) {
            Log::error("notifySpecificSupplier FAILED: Contact user has no email", [
                'contact_user_id' => $contactUser->id
            ]);
            return;
        }

        $receiver = (object) [
            'contact_name' => $contactUser->name ?? 'there',
            'email' => $profile->email,
            'company_name' => $company->name ?? '',
        ];

        Log::info("notifySpecificSupplier: Receiver Identified", [
            'supplier_company' => $receiver->company_name,
            'email' => $receiver->email
        ]);

        /**
         * Build supplier-specific product totals
         */
        $supplierProductsBreakdown = [];
        $grandTotal = 0;

        foreach ($updatedItems as $item) {

            // supplier-specific price
            $supplierProduct = $supplyJob->products
                ->where('product_id', $item['product_id'])
                ->first();

            if (!$supplierProduct) {
                Log::warning("Supplier product not found for updated item", [
                    'product_id' => $item['product_id'],
                    'supply_job_id' => $supplyJobId
                ]);
                continue;
            }

            $price = $supplierProduct->price_per_unit ?? 0;
            $total = $price * $item['new_qty'];

            $supplierProductsBreakdown[] = [
                'psm_code' => $item['psm_code'],
                'model' => $item['model'],
                'software_code' => $item['software_code'],
                'old_qty' => $item['old_qty'],
                'new_qty' => $item['new_qty'],
                'price' => $price,
                'total' => $total,
            ];

            $grandTotal += $total;

            Log::info("notifySpecificSupplier: Product computed", [
                'product_id' => $item['product_id'],
                'old_qty' => $item['old_qty'],
                'new_qty' => $item['new_qty'],
                'price' => $price,
                'total' => $total,
            ]);
        }

        // Send email
        try {
            Mail::to($receiver->email)->send(
                new RentalJobQuantityUpdated(
                    $rentalJob,
                    $supplyJob,
                    $receiver,
                    $supplierProductsBreakdown,
                    $grandTotal
                )
            );

            Log::info("notifySpecificSupplier: Email sent successfully", [
                'email' => $receiver->email,
                'supply_job_id' => $supplyJobId,
                'grand_total' => $grandTotal
            ]);

        } catch (\Throwable $e) {
            Log::error("notifySpecificSupplier: Email FAILED", [
                'error' => $e->getMessage(),
                'supply_job_id' => $supplyJobId
            ]);
        }
    }

    /**
     * Notify all suppliers about updated product quantities.
     * Each supplier sees THEIR price + their total.
     */
    private function notifySuppliersAboutQuantityUpdate($rentalJob, $updatedProducts)
    {
        Log::info('Inside notifySuppliersAboutQuantityUpdate', [
            'job_id' => $rentalJob->id,
            'updated_products' => $updatedProducts
        ]);

        $supplyJobs = SupplyJob::where('rental_job_id', $rentalJob->id)
            ->with(['products']) // products contain price_per_unit
            ->get();

        foreach ($supplyJobs as $supplyJob) {

            $company = Company::find($supplyJob->provider_id);
            if (!$company)
                continue;

            $contactUser = User::find($company->default_contact_id);
            if (!$contactUser)
                continue;

            $profile = UserProfile::where('user_id', $contactUser->id)->first();
            if (!$profile || !$profile->email)
                continue;

            $receiver = (object) [
                'contact_name' => $contactUser->name ?? 'there',
                'email' => $profile->email,
                'company_name' => $company->name ?? '',
            ];

            /**
             * Supplier-specific prices must be computed here,
             * because each supplier has different price_per_unit.
             */
            $supplierProductTotals = [];
            $grandTotal = 0;

            foreach ($updatedProducts as $item) {

                $supplierProduct = $supplyJob->products
                    ->where('product_id', $item['product_id'])
                    ->first();

                $price = $supplierProduct->price_per_unit ?? 0;
                $total = $price * $item['new_qty'];
                $grandTotal += $total;

                $supplierProductTotals[] = [
                    'psm_code' => $item['psm_code'],
                    'model' => $item['model'],
                    'software_code' => $item['software_code'],
                    'old_qty' => $item['old_qty'],
                    'new_qty' => $item['new_qty'],
                    'price' => $price,
                    'total' => $total,
                ];
            }

            // Send supplier-specific email
            Mail::to($receiver->email)->send(
                new RentalJobQuantityUpdated(
                    $rentalJob,
                    $supplyJob,
                    $receiver,
                    $supplierProductTotals,
                    $grandTotal
                )
            );
        }
    }

    /**
     * Cancel a rental job along with all its supply jobs and offers.
     * Only the job owner can cancel.
     */
    public function cancelRentalJob(Request $request, int $rentalJobId)
    {
        $user = auth('api')->user();

        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {

            $rentalJob = RentalJob::with([
                'supplyJobs.supplyJobOffers',
                'supplyJobs.provider.defaultContact.profile',
                'products',                     // rental job products pivot
                'products.product.equipments',  // product → equipment details
            ])->findOrFail($rentalJobId);

            // Authorization
            if ($rentalJob->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to cancel this rental job.'
                ], 403);
            }

            if ($rentalJob->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'This rental job is already cancelled.'
                ], 422);
            }

            DB::transaction(function () use ($rentalJob, $request, $user) {

                /** ------------------------------------------
                 * 1. Update Rental Job status
                 * ------------------------------------------ */
                $rentalJob->update([
                    'status' => 'cancelled',
                    'cancelled_by' => $user->id,
                    'notes' => $request->reason,
                ]);

                $requesterCompany = $user->company;

                /** ------------------------------------------
                 * 2. Cancel Related Supply Jobs + Offers
                 * ------------------------------------------ */
                foreach ($rentalJob->supplyJobs as $supplyJob) {

                    $supplyJob->update([
                        'status' => 'cancelled',
                        'cancelled_by' => $user->id,
                        'notes' => 'Cancelled by the requester',
                    ]);
                    // Force-load the offers relationship
                    $supplyJob->load('supplyJobOffers');

                    foreach ($supplyJob->supplyJobOffers as $offer) {
                        $offer->update(['status' => 'cancelled']);
                    }

                    /** ------------------------------------------
                     * 3. Prepare Rental Job Product Details
                     * ------------------------------------------ */
                    $products = SupplyJobProduct::with(['product.getEquipment'])
                        ->where('supply_job_id', $supplyJob->id)
                        ->get()
                        ->map(function ($item) {

                            $equipment = $item->product->getEquipment->first() ?? null;

                            return [
                                'psm_code' => $item->product->psm_code ?? '-',
                                'model' => $item->product->model ?? '-',
                                'software_code' => $equipment->software_code ?? '-',

                                // IMPORTANT — use correct supply_job_products fields
                                'quantity' => $item->offered_quantity ?? 0,
                                'price' => $item->price_per_unit ?? 0,
                                'total_price' => ($item->offered_quantity ?? 0) * ($item->price_per_unit ?? 0),
                            ];
                        })
                        ->toArray();

                    /** ------------------------------------------
                     * 4. Send Email to Provider
                     * ------------------------------------------ */
                    $providerContact = $supplyJob->provider->defaultContact->profile ?? null;
                    $email = $providerContact->email ?? null;

                    if ($email) {

                        $mailData = [
                            'receiver_contact_name' => $providerContact->name ?? 'there',
                            'requester_company_name' => $requesterCompany->name ?? '-',
                            'rental_job_name' => $rentalJob->name,
                            'supply_job_name' => $supplyJob->name,
                            'status' => 'Cancelled by User',
                            'reason' => $request->reason ?? 'No reason provided.',
                            'date' => now()->format('d M Y, h:i A'),
                            'products' => $products,
                            'currency' => $requesterCompany->currency->symbol ?? '₹',
                        ];

                        Mail::send('emails.rentalJobCancelled', $mailData, function ($message) use ($email) {
                            $message->to($email)
                                ->subject('Rental Job Cancelled - Pro Subrental Marketplace')
                                ->from(config('mail.from.address'), config('mail.from.name'));
                        });
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Rental job and related supply jobs cancelled successfully.',
            ]);

        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel rental job.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Renter submits a star rating (and optional comment) for a completed job.
     */
    public function rate(Request $request, int $id)
    {
        $user = auth('api')->user();

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        try {
            $rentalJob = RentalJob::with('user')->findOrFail($id);

            if ((int) $rentalJob->user->company_id !== (int) $user->company_id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized for this rental job.'
                ], 403);
            }

            if ($rentalJob->status !== 'completed_pending_rating') {
                return response()->json([
                    'success' => false,
                    'message' => 'Job must be in completed (pending rating) status to submit a rating.'
                ], 400);
            }

            DB::transaction(function () use ($rentalJob, $validated) {
                $pendingSupplyJobs = SupplyJob::where('rental_job_id', $rentalJob->id)
                    ->where('status', 'completed_pending_rating')
                    ->get();
                foreach ($pendingSupplyJobs as $sj) {
                    JobRating::updateOrCreate(
                        ['supply_job_id' => $sj->id],
                        [
                            'rental_job_id' => $rentalJob->id,
                            'rating' => $validated['rating'],
                            'comment' => $validated['comment'] ?? null,
                            'rated_at' => now(),
                            'skipped_at' => null,
                        ]
                    );
                }
                $rentalJob->update(['status' => 'rated']);
                SupplyJob::where('rental_job_id', $rentalJob->id)
                    ->where('status', 'completed_pending_rating')
                    ->update(['status' => 'rated']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Rating submitted successfully',
                'data' => [],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Rental job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to submit rating.'], 500);
        }
    }

    /**
     * Renter explicitly skips rating.
     */
    public function rateSkip(Request $request, int $id)
    {
        $user = auth('api')->user();

        try {
            $rentalJob = RentalJob::with('user')->findOrFail($id);

            if ((int) $rentalJob->user->company_id !== (int) $user->company_id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized for this rental job.'
                ], 403);
            }

            if ($rentalJob->status !== 'completed_pending_rating') {
                return response()->json([
                    'success' => false,
                    'message' => 'Job must be in completed (pending rating) status to skip rating.'
                ], 400);
            }

            DB::transaction(function () use ($rentalJob) {
                $pendingSupplyJobs = SupplyJob::where('rental_job_id', $rentalJob->id)
                    ->where('status', 'completed_pending_rating')
                    ->get();
                foreach ($pendingSupplyJobs as $sj) {
                    JobRating::updateOrCreate(
                        ['supply_job_id' => $sj->id],
                        [
                            'rental_job_id' => $rentalJob->id,
                            'skipped_at' => now(),
                        ]
                    );
                }
                $rentalJob->update(['status' => 'rated']);
                SupplyJob::where('rental_job_id', $rentalJob->id)
                    ->where('status', 'completed_pending_rating')
                    ->update(['status' => 'rated']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Rating skipped.',
                'data' => [],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Rental job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to skip rating.'], 500);
        }
    }

}
