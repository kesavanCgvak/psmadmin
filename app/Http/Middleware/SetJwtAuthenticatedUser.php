<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class SetJwtAuthenticatedUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = JWTAuth::parseToken()->authenticate();

        if ($user) {
            auth('api')->setUser($user);
            $request->setUserResolver(static fn () => $user);
        }

        return $next($request);
    }
}
