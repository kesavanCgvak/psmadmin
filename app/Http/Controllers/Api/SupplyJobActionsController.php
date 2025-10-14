<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplyJob;
use App\Models\SupplyJobProduct;
use App\Models\SupplyJobOffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\RentalJob;
use App\Models\User;
use App\Models\Company;
use Illuminate\Support\Facades\Mail;


class SupplyJobActionsController extends Controller
{
    /**
     * Helper: authorize a provider-company user against a supply job.
     * provider_id on supply_jobs == companies.id
     */
    protected function authorizeCompany(SupplyJob $supplyJob, $user): ?\Illuminate\Http\JsonResponse
    {
        if ($user->is_admin) {
            return null;
        }

        if ((int) $user->company_id !== (int) $supplyJob->provider_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized for this supply job.'
            ], 403);
        }

        return null;
    }

    /**
     * Update provider-side milestone dates: pack_at, deliver_at, return_by, unpack_at.
     */
    public function updateMilestoneDates(Request $request, int $id)
    {
        $user = auth('api')->user();

        $validator = Validator::make($request->all(), [
            'packing_date' => 'nullable|date|required_without_all:delivery_date,return_date,unpacking_date',
            'delivery_date' => 'nullable|date|required_without_all:packing_date,return_date,unpacking_date',
            'return_date' => 'nullable|date|required_without_all:packing_date,delivery_date,unpacking_date',
            'unpacking_date' => 'nullable|date|required_without_all:packing_date,delivery_date,return_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payload.',
                'errors' => $validator->errors()
            ], 422);
        }

        $allowedKeys = ['packing_date', 'delivery_date', 'return_date', 'unpacking_date'];
        $extraKeys = collect(array_keys($request->all()))->diff($allowedKeys);
        if ($extraKeys->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Unexpected fields in request: ' . $extraKeys->implode(', ')
            ], 422);
        }

        try {
            $supplyJob = SupplyJob::findOrFail($id);

            if ($resp = $this->authorizeCompany($supplyJob, $user)) {
                return $resp;
            }

            if (in_array($supplyJob->status, ['cancelled', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update dates for cancelled or completed jobs.'
                ], 422);
            }

            $supplyJob->fill($validator->validated())->save();

            return response()->json([
                'success' => true,
                'message' => 'Milestone dates updated successfully.'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Supply job not found.'
            ], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update milestone dates.'
            ], 500);
        }
    }

    /**
     * Update supplier product lines: can_supply, price_per_unit.
     * Payload: items: [ {product_id, can_supply, price_per_unit}, ... ]
     */
    public function updateSupplyQuantities(Request $request, int $id)
    {
        $user = auth('api')->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct',
            'items.*.can_supply' => 'required|integer|min:0',
            'items.*.price_per_unit' => 'nullable|numeric|min:0',
        ]);

        try {
            $supplyJob = SupplyJob::with('products')->findOrFail($id);

            if ($resp = $this->authorizeCompany($supplyJob, $user)) {
                return $resp;
            }

            if (in_array($supplyJob->status, ['cancelled', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update quantities for cancelled or completed jobs.'
                ], 422);
            }

            DB::transaction(function () use ($supplyJob, $validated) {
                $incomingIds = collect($validated['items'])->pluck('product_id');

                // Remove old products not included in the new list
                $supplyJob->products()->whereNotIn('product_id', $incomingIds)->delete();

                // Update or insert new items
                foreach ($validated['items'] as $item) {
                    $sp = $supplyJob->products()->where('product_id', $item['product_id'])->first();

                    if (!$sp) {
                        $sp = new SupplyJobProduct(['product_id' => $item['product_id']]);
                        $supplyJob->products()->save($sp);
                    }

                    $sp->offered_quantity = $item['can_supply'];
                    if (array_key_exists('price_per_unit', $item)) {
                        $sp->price_per_unit = $item['price_per_unit'];
                    }
                    $sp->save();
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Supply quantities updated successfully.'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to update supply quantities.'], 500);
        }
    }

    /**
     * Send a new offer for the supply job.
     * Creates a new SupplyJobOffer, links it to the SupplyJob, and sends notification emails.
     * Payload: { amount: numeric }
     */
    public function sendNewOffer(Request $request, int $id)
    {
        $user = auth('api')->user();

        $data = $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        try {
            $supplyJob = SupplyJob::with('rentalJob')->findOrFail($id);

            if ($resp = $this->authorizeCompany($supplyJob, $user)) {
                return $resp;
            }

            $rentalJob = $supplyJob->rentalJob;
            if (!$rentalJob) {
                return response()->json(['success' => false, 'message' => 'Associated rental job not found.'], 404);
            }

            // Determine next version
            $lastVersion = SupplyJobOffer::where('rental_job_id', $rentalJob->id)->max('version');
            $nextVersion = ($lastVersion ?? 0) + 1;

            // Save offer
            $offer = SupplyJobOffer::create([
                'rental_job_id' => $rentalJob->id,
                'version' => $nextVersion,
                'total_price' => $data['amount'],
                'status' => 'pending',
            ]);

            // Collect emails
            $emails = [];

            // 1️⃣ Rental job user email
            $rentalUser = User::with('profile')->find($rentalJob->user_id);
            if ($rentalUser && $rentalUser->profile && $rentalUser->profile->email) {
                $emails[] = $rentalUser->profile->email;
            }

            // 2️⃣ Default contact of that user’s company
            if ($rentalUser && $rentalUser->company_id) {
                $company = Company::with('defaultContact.profile')->find($rentalUser->company_id);

                if ($company && $company->defaultContact && $company->defaultContact->profile) {
                    $defaultEmail = $company->defaultContact->profile->email;
                    if ($defaultEmail) {
                        $emails[] = $defaultEmail;
                    }
                }
            }

            // Remove duplicates
            $emails = array_unique(array_filter($emails));

            // Prepare mail content
            $mailContent = [
                'provider_name' => $user->company ? $user->company->name : 'A Supplier',
                'rental_job_id' => $rentalJob->id,
                'amount' => number_format($data['amount'], 2),
                'version' => $nextVersion,
                'sent_at' => now()->format('d M Y, h:i A'),
            ];

            // Send emails
            foreach ($emails as $email) {
                Mail::send('emails.supplyNewOffer', $mailContent, function ($message) use ($email) {
                    $message->to($email)
                        ->subject('New Offer from Pro Subrental Marketplace')
                        ->from('acctracking001@gmail.com', 'Pro Subrental Marketplace');
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Offer sent successfully and notification emails delivered.',
                'data' => [
                    'id' => $offer->id,
                    'rental_job_id' => $rentalJob->id,
                    'version' => $offer->version,
                    'total_price' => $offer->total_price,
                    'status' => $offer->status,
                    'notified_emails' => $emails,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to send offer.'], 500);
        }
    }


    /**
     * Handshake = accept offer.
     * Updates both supply job status and (optionally) rental job status.
     */
    public function handshake(Request $request, int $id)
    {
        $user = auth('api')->user();

        try {
            $supplyJob = SupplyJob::with('rentalJob')->findOrFail($id);

            if ($resp = $this->authorizeCompany($supplyJob, $user)) {
                return $resp;
            }

            if (in_array($supplyJob->status, ['accepted', 'cancelled', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot handshake in current job status.'
                ], 422);
            }

            DB::transaction(function () use ($supplyJob) {
                $supplyJob->status = 'accepted';
                $supplyJob->save();

                if ($supplyJob->rentalJob && $supplyJob->rentalJob->status !== 'accepted') {
                    $supplyJob->rentalJob->status = 'accepted';
                    $supplyJob->rentalJob->save();
                }
            });

            return response()->json(['success' => true, 'message' => 'Handshake successful.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to handshake.'], 500);
        }
    }

    /**
     * Cancel negotiation.
     */
    public function cancelNegotiation(Request $request, int $id)
    {
        $user = auth('api')->user();

        try {
            $supplyJob = SupplyJob::findOrFail($id);

            if ($resp = $this->authorizeCompany($supplyJob, $user)) {
                return $resp;
            }

            if (in_array($supplyJob->status, ['cancelled', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Job is already cancelled or completed.'
                ], 422);
            }

            $supplyJob->status = 'cancelled';
            $supplyJob->save();

            return response()->json(['success' => true, 'message' => 'Negotiation cancelled successfully.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to cancel negotiation.'], 500);
        }
    }
}
