<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SupportSessionException;
use App\Http\Controllers\Controller;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\SupportSessionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SupportSessionController extends Controller
{
    public function __construct(private readonly SupportSessionService $sessions)
    {
    }

    public function start(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();
        if (! $admin) {
            return $this->error('Authentication required.', 401);
        }

        try {
            $result = $this->sessions->start($admin, $user, $request);
        } catch (SupportSessionException $e) {
            return $this->error($e->getMessage(), $e->status);
        } catch (QueryException $e) {
            Log::error('Assume identity start failed', [
                'admin_user_id' => $admin->id,
                'assumed_user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Unable to start a support session.', 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Support session started.',
            'data' => $this->sessions->present($result['session'], $result['token']),
        ]);
    }

    public function end(Request $request, SupportSession $supportSession): JsonResponse
    {
        $admin = $request->user();
        if (! $admin) {
            return $this->error('Authentication required.', 401);
        }

        try {
            $session = $this->sessions->endForAdministrator($admin, $supportSession);
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
