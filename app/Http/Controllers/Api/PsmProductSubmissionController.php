<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePsmProductSubmissionRequest;
use App\Models\PsmProductSubmission;
use App\Services\PsmProductSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class PsmProductSubmissionController extends Controller
{
    public function __construct(
        private readonly PsmProductSubmissionService $submissionService,
    ) {}

    /**
     * GET /api/psm-product-submissions
     *
     * Company-scoped submissions for the authenticated user (including approved/soft-deleted).
     */
    public function index(Request $request): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (! $user?->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any company.',
            ], 404);
        }

        $request->validate([
            'status' => 'nullable|in:pending,approved,rejected',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) $request->query('per_page', config('app.admin_list_per_page', 25));

        $query = PsmProductSubmission::withTrashed()
            ->where('company_id', $user->company_id)
            ->with(['brand:id,name'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'PSM product submissions fetched successfully.',
            'data' => $paginator->getCollection()
                ->map(fn (PsmProductSubmission $submission) => $this->submissionService->formatForApi($submission))
                ->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (! $user?->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any company.',
            ], 404);
        }

        $submission = PsmProductSubmission::withTrashed()
            ->where('company_id', $user->company_id)
            ->find($id);

        if (! $submission) {
            return response()->json([
                'success' => false,
                'message' => 'Submission not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'PSM product submission fetched successfully.',
            'data' => $this->submissionService->formatForApi($submission, true),
        ]);
    }

    public function store(StorePsmProductSubmissionRequest $request): JsonResponse
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (! $user?->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'User does not belong to any company.',
            ], 404);
        }

        if (strtolower((string) $user->account_type) !== 'provider') {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'ACCOUNT_NOT_PROVIDER',
                    'message' => 'Only provider accounts are allowed to perform this action.',
                ],
            ], 403);
        }

        try {
            $images = $request->file('images', []);
            if (! is_array($images)) {
                $images = $images ? [$images] : [];
            }

            $submission = $this->submissionService->submit(
                $user,
                $request->validated(),
                array_values($images),
                $request->filled('primary_image_index') ? (int) $request->input('primary_image_index') : null,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product submitted for admin verification. It has not been added to PSM inventory yet.',
            'data' => $this->submissionService->formatForApi($submission, true),
        ], 201);
    }
}
