<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\ProviderApiKeyMiddleware;
use App\Http\Middleware\RequireSubscription;
use App\Http\Middleware\SetJwtAuthenticatedUser;
use App\Support\ChatLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => ['api', 'jwt.verify', SetJwtAuthenticatedUser::class],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt.verify' => JwtMiddleware::class,
            'provider.api.key' => ProviderApiKeyMiddleware::class,
            'require.subscription' => RequireSubscription::class,
            'admin.access' => AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (AccessDeniedHttpException $e) {
            $request = request();
            if ($request->is('api/broadcasting/auth')) {
                ChatLog::warning('Unauthorized broadcast channel subscription', [
                    'user_id' => $request->user('api')?->id,
                    'company_id' => $request->user('api')?->company_id,
                    'channel' => $request->input('channel_name'),
                ]);
            }
        });

        $exceptions->render(function (TokenExpiredException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Session expired',
            ], 401);
        });

        $exceptions->render(function (TokenInvalidException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token',
            ], 401);
        });

        $exceptions->render(function (JWTException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Token not provided',
            ], 401);
        });
    })->create();
