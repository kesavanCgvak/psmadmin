<?php

namespace App\Http\Middleware;

use App\Exceptions\SupportSessionException;
use App\Models\SupportSession;
use App\Services\SupportSessionAccessPolicy;
use App\Services\SupportSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSupportSessionRestrictions
{
    public function __construct(
        private readonly SupportSessionService $sessions,
        private readonly SupportSessionAccessPolicy $policy
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $session = $this->sessions->authenticate($request);
        } catch (SupportSessionException $e) {
            $this->sessions->clearTokenFactory();

            return $this->error($e->getMessage(), $e->status);
        }

        if (! $session instanceof SupportSession) {
            return $this->finish($request, $next);
        }

        $assumed = $session->assumedUser;
        if ($assumed) {
            auth('api')->setUser($assumed);
        }

        $request->attributes->set('support_session', $session);
        $request->attributes->set('support_admin_id', $session->admin_user_id);

        if (! $this->policy->allows($request)) {
            $this->sessions->logBlocked($session, $request);
            $this->sessions->clearTokenFactory();

            return $this->error(
                'This action is not permitted during a read-only support session.',
                403
            );
        }

        return $this->finish($request, $next);
    }

    private function finish(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            $this->sessions->clearTokenFactory();
        }
    }

    private function error(string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
