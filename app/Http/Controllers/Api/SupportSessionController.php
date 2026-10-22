<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SupportSessionException;
use App\Http\Controllers\Controller;
use App\Services\SupportSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assume Identity support sessions.
 *
 * Start (super admin web session):
 *   POST /admin/users/{user}/assume-identity
 *   200 data: session_id, read_only, status, expires_at, expires_in,
 *       token_type, access_token, assumed_user (id, username, name, company,
 *       account_type, company_id, is_admin, role, company_profile),
 *       administrator_id.
 *       company stays the company name. company_profile is additive and has no secrets.
 *   401 unauthenticated, 403 insufficient permission, 404 unknown user,
 *   422 own identity or ineligible account, 409 conflicting session, 429 rate limit.
 *
 * Current state (support credential):
 *   GET /api/support-sessions/current
 *
 * End (support credential; the only support-session write that is allowed):
 *   POST /api/support-sessions/current/end
 *
 * End (original administrator session):
 *   POST /admin/support-sessions/{supportSession}/end
 *
 * The access token is a short-lived JWT. Its subject is the assumed user and
 * its token_use claim is "support". State-changing API calls are rejected.
 */
class SupportSessionController extends Controller
{
    public function __construct(private readonly SupportSessionService $sessions)
    {
    }

    public function current(Request $request): JsonResponse
    {
        try {
            $session = $this->sessions->authenticate($request);
        } catch (SupportSessionException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        if ($session === null) {
            return $this->error('A support session is required.', 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessions->present($session),
        ]);
    }

    public function end(Request $request): JsonResponse
    {
        try {
            $session = $this->sessions->endForSupportRequest($request);
        } catch (SupportSessionException $e) {
            return $this->error($e->getMessage(), $e->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support session ended.',
            'data' => $this->sessions->present($session),
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
