<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupplyJob;
use App\Models\RentalJobComment;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    /**
     * Get comments for a supply job (and rental job).
     * GET /api/supply-jobs/{supplyJobId}/comments
     */
    public function index(Request $request, int $supplyJobId)
    {
        $user = auth('api')->user();

        try {
            $sj = SupplyJob::with(['rentalJob', 'providerCompany:id,name'])
                ->find($supplyJobId);

            if (!$sj) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supply job not found.'
                ]);
            }

            $authorized =
                $user->is_admin ||
                $sj->rentalJob?->user_id === $user->id ||
                (int) $user->company_id === (int) $sj->provider_id;

            if (!$authorized) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }

            $comments = $sj->rentalJob->comments()
                ->where('supply_job_id', $supplyJobId)
                ->with(['sender:id', 'sender.profile:id,user_id,full_name'])
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($comment) use ($sj) {
                    return [
                        'id' => $comment->id,
                        'message' => $comment->message,
                        'sender' => [
                            'id' => $comment->sender->id,
                            'name' => $comment->sender->profile->full_name ?? null,
                        ],
                        'supplier' => [
                            'id' => $sj->providerCompany->id ?? null,
                            'name' => $sj->providerCompany->name ?? null,
                        ],
                        'created_at' => $comment->created_at?->toIso8601String(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $comments
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch comments.'
            ]);
        }
    }

    /**
     * Add a comment on a supply job.
     * POST /api/supply-jobs/{supplyJobId}/comments
     */
    public function store(Request $request, int $supplyJobId)
    {
        $user = auth('api')->user();

        $data = $request->validate([
            'message' => 'required|string|min:1|max:2000',
        ]);

        try {
            $sj = SupplyJob::with('rentalJob')->find($supplyJobId);

            if (!$sj) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supply job not found.'
                ]);
            }

            $authorized =
                $user->is_admin ||
                $sj->rentalJob?->user_id === $user->id ||
                (int) $user->company_id === (int) $sj->provider_id;

            if (!$authorized) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $comment = new RentalJobComment();
            $comment->supply_job_id = $supplyJobId;
            $comment->rental_job_id = $sj->rentalJob->id;
            $comment->sender_id = $user->id;
            $comment->message = $data['message'];
            $comment->save();

            return response()->json([
                'success' => true,
                'message' => 'Comment added.',
                'data' => [
                    'id' => $comment->id,
                    'message' => $comment->message,
                    'created_at' => $comment->created_at?->toIso8601String(),
                ]
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to add comment.']);
        }
    }

    /**
     * Edit a comment (only author or admin).
     * PUT /api/comments/{commentId}
     */
    public function update(Request $request, int $commentId)
    {
        $user = auth('api')->user();

        $data = $request->validate([
            'message' => 'required|string|min:1|max:2000',
        ]);

        try {
            $comment = RentalJobComment::find($commentId);

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Comment not found.'
                ]);
            }

            if ($comment->sender_id !== $user->id && !$user->is_admin) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $comment->message = $data['message'];
            $comment->save();

            return response()->json(['success' => true, 'message' => 'Comment updated.']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to update comment.']);
        }
    }

    /**
     * Delete a comment (only author or admin).
     * DELETE /api/comments/{commentId}
     */
    public function destroy(Request $request, int $commentId)
    {
        $user = auth('api')->user();

        try {
            $comment = RentalJobComment::find($commentId);

            if (!$comment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Comment not found.'
                ]);
            }

            if ($comment->sender_id !== $user->id && !$user->is_admin) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $comment->delete();

            return response()->json(['success' => true, 'message' => 'Comment deleted.']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Failed to delete comment.']);
        }
    }
}
