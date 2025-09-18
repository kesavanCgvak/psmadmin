<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplyJob;
use App\Models\SupplyJobProduct;
use App\Models\RentalJobOffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            return response()->json(['success' => false, 'message' => 'Unauthorized for this supply job.'], 403);
        }
        return null;
    }

    /**
     * Update provider-side milestone dates: pack_at, deliver_at, return_by, unpack_at.
     */
    public function updateMilestoneDates(Request $request, int $id)
    {
       $user = auth('api')->user();

        $data = $request->validate([
            'packing_date'    => 'nullable|date',
            'delivery_date' => 'nullable|date',
            'return_date'  => 'nullable|date',
            'unpacking_date'  => 'nullable|date',
        ]);

        try {
            $sj = SupplyJob::findOrFail($id);

            if ($resp = $this->authorizeCompany($sj, $user)) {
                return $resp;
            }

            $sj->fill($data)->save();

            return response()->json(['success' => true, 'message' => 'Dates updated.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to update dates.'], 500);
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
            'items.*.product_id'     => 'required|integer|distinct',
            'items.*.can_supply'     => 'required|integer|min:0',
            'items.*.price_per_unit' => 'nullable|numeric|min:0',
        ]);

        try {
            $sj = SupplyJob::with('products')->findOrFail($id);

            if ($resp = $this->authorizeCompany($sj, $user)) {
                return $resp;
            }

            DB::transaction(function () use ($sj, $validated) {
                foreach ($validated['items'] as $item) {
                    /** @var SupplyJobProduct|null $sp */
                    $sp = $sj->products()->where('product_id', $item['product_id'])->first();

                    if (!$sp) { // create if not exists
                        $sp = new SupplyJobProduct([
                            'product_id' => $item['product_id'],
                        ]);
                        $sj->products()->save($sp);
                    }

                    $sp->offered_quantity = $item['can_supply'];
                    if (array_key_exists('price_per_unit', $item)) {
                        $sp->price_per_unit = $item['price_per_unit'];
                    }
                    $sp->save();
                }
            });

            return response()->json(['success' => true, 'message' => 'Supply quantities updated.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Update failed.'], 500);
        }
    }

    /**
     * Send a new offer/quote (integer as requested).
     * Body: { amount: <int> }
     * Creates a new version automatically (version = last + 1).
     */
    public function sendNewOffer(Request $request, int $id)
    {
       $user = auth('api')->user();

        $data = $request->validate([
            'amount' => 'required|integer|min:0',
        ]);

        try {
            $sj = SupplyJob::with('offers')->findOrFail($id);

            if ($resp = $this->authorizeCompany($sj, $user)) {
                return $resp;
            }

            $nextVersion = ($sj->offers->max('version') ?? 0) + 1;

            $offer = new RentalJobOffer();
            $offer->supply_job_id = $sj->id;
            $offer->version       = $nextVersion;
            $offer->total_price   = $data['amount']; // integer as per requirement
            $offer->status        = 'pending';
            $offer->save();

            return response()->json([
                'success' => true,
                'message' => 'Offer sent.',
                'data'    => [
                    'id' => $offer->id,
                    'version' => $offer->version,
                    'total_price' => $offer->total_price,
                    'status' => $offer->status,
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
     * Handshake = accept. Updates both supply job status and (optionally) rental job status.
     */
    public function handshake(Request $request, int $id)
    {
       $user = auth('api')->user();

        try {
            $sj = SupplyJob::with('rentalJob')->findOrFail($id);

            if ($resp = $this->authorizeCompany($sj, $user)) {
                return $resp;
            }

            DB::transaction(function () use ($sj) {
                $sj->status = 'accepted';
                $sj->save();

                // If you want to mark the rental job accepted when any provider accepted:
                if ($sj->rentalJob && $sj->rentalJob->status !== 'accepted') {
                    $sj->rentalJob->status = 'accepted';
                    $sj->rentalJob->save();
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
            $sj = SupplyJob::findOrFail($id);

            if ($resp = $this->authorizeCompany($sj, $user)) {
                return $resp;
            }

            $sj->status = 'cancelled';
            $sj->save();

            return response()->json(['success' => true, 'message' => 'Negotiation cancelled.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Supply job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to cancel.'], 500);
        }
    }
}
