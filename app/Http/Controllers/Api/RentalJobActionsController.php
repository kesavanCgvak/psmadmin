<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RentalJob;
use App\Models\RentalJobProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'status' => 'prohibited', // status changes come via handshake/cancel endpoints
        ]);

        try {
            $job = RentalJob::query()->findOrFail($id);

            if ($job->user_id !== $user->id && !$user->is_admin) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $job->fill($data)->save();

            return response()->json(['success' => true, 'message' => 'Rental job updated.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Rental job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Update failed.'], 500);
        }
    }

    /**
     * Update requested quantities for products on a rental job.
     * Payload: items: [ {product_id, requested_quantity}, ... ]
     * Only owner or admin.
     */
    public function updateRequestedQuantities(Request $request, int $id)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct',
            'items.*.requested_quantity' => 'required|integer|min:0',
        ]);

        try {
            $job = RentalJob::with('products')->findOrFail($id);

            if ($job->user_id !== $user->id && !$user->is_admin) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            DB::transaction(function () use ($job, $validated) {
                foreach ($validated['items'] as $item) {
                    /** @var RentalJobProduct $rjp */
                    $rjp = $job->products()->where('product_id', $item['product_id'])->first();
                    if (!$rjp) {
                        // If you want to allow adding new product lines, create here; otherwise, skip
                        continue;
                    }
                    $rjp->requested_quantity = $item['requested_quantity'];
                    $rjp->save();
                }
            });

            return response()->json(['success' => true, 'message' => 'Requested quantities updated.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Rental job not found.'], 404);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Update failed.'], 500);
        }
    }
}
