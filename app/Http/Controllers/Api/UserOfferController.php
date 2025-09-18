<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RentalJob;
use App\Models\SupplyJob;
use App\Models\RentalJobOffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserOfferController extends Controller
{
    /**
     * User sends an offer (quote) to a provider for a rental job.
     * Body: { provider_company_id, amount, message? }
     */
    public function sendOfferToProvider(Request $request, int $jobId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $data = $request->validate([
                'provider_company_id' => 'required|integer|exists:companies,id',
                'amount' => 'required|integer|min:0',
                'message' => 'nullable|string|max:2000',
            ]);

            $job = RentalJob::with('supplyJobs')->find($jobId);

            if (!$job) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rental job not found.'
                ]);
            }

            // Authorization: only job owner or admin can send offers
            if ($job->user_id !== $user->id && !$user->is_admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }

            // Ensure a supply job exists for this provider, otherwise create one
            $supplyJob = $job->supplyJobs()
                ->where('provider_id', $data['provider_company_id'])
                ->first();

            if (!$supplyJob) {
                $supplyJob = new SupplyJob([
                    'provider_id' => $data['provider_company_id'],
                    'status' => 'negotiating',
                ]);
                $job->supplyJobs()->save($supplyJob);
            }

            // Create offer with next version
            $nextVersion = ($supplyJob->offers->max('version') ?? 0) + 1;

            $offer = new RentalJobOffer();
            $offer->supply_job_id = $supplyJob->id;
            $offer->version = $nextVersion;
            $offer->total_price = $data['amount'];
            $offer->status = 'pending';
            $offer->save();

            // optional: log message as comment
            if (!empty($data['message'])) {
                $supplyJob->comments()->create([
                    'supply_job_id' => $supplyJob->id,
                    'rental_job_id' => $job->id,
                    'sender_id' => $user->id,
                    'message' => $data['message'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Offer sent to provider.',
                'data' => [
                    'id' => $offer->id,
                    'job_id' => $job->id,
                    'provider_company_id' => $data['provider_company_id'],
                    'version' => $offer->version,
                    'amount' => $offer->total_price,
                    'status' => $offer->status,
                ]
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send offer.'
            ], 500);
        }
    }
}
