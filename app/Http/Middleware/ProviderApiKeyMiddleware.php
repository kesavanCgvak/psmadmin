<?php

namespace App\Http\Middleware;

use App\Models\ProviderApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProviderApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = $this->extractApiKey($request);
        if (!$rawKey) {
            Log::warning('Partner API auth failed: missing API key', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API key is required.',
            ], 401);
        }

        $hashedKey = hash('sha256', $rawKey);
        $apiKey = ProviderApiKey::with('providerUser.company')
            ->where('key_hash', $hashedKey)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->first();

        if (!$apiKey) {
            Log::warning('Partner API auth failed: invalid API key', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'key_prefix' => substr($rawKey, 0, 10),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid API key.',
            ], 401);
        }

        if ($apiKey->expires_at && now()->greaterThan($apiKey->expires_at)) {
            Log::warning('Partner API auth failed: expired API key', [
                'provider_user_id' => $apiKey->provider_user_id,
                'api_key_id' => $apiKey->id,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API key has expired.',
            ], 403);
        }

        $providerUser = $apiKey->providerUser;
        if (!$providerUser || !$providerUser->company || $providerUser->company->account_type !== 'provider') {
            Log::warning('Partner API auth failed: API key linked to invalid provider context', [
                'provider_user_id' => $apiKey->provider_user_id,
                'api_key_id' => $apiKey->id,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API key is not linked to a valid provider account.',
            ], 403);
        }

        $request->attributes->set('provider_api_key', $apiKey);
        $request->attributes->set('provider_user', $providerUser);
        $request->attributes->set('provider_company', $providerUser->company);

        $apiKey->forceFill(['last_used_at' => now()])->save();

        Log::info('Partner API auth success', [
            'provider_user_id' => $providerUser->id,
            'provider_company_id' => $providerUser->company->id,
            'api_key_id' => $apiKey->id,
            'key_prefix' => $apiKey->key_prefix,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return $next($request);
    }

    private function extractApiKey(Request $request): ?string
    {
        $authHeader = (string) $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        $headerKey = $request->header('X-API-KEY');
        if (is_string($headerKey) && trim($headerKey) !== '') {
            return trim($headerKey);
        }

        return null;
    }
}

