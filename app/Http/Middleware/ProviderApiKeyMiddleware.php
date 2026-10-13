<?php

namespace App\Http\Middleware;

use App\Models\ProviderApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProviderApiKeyMiddleware
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $rawKey = self::extractApiKey($request);
        if (! $rawKey) {
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

        if (! $apiKey) {
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
        $company = $providerUser?->company;
        $companyIsProvider = $company
            && strtolower((string) ($company->account_type ?? '')) === 'provider';
        $userIsProvider = $providerUser
            && strtolower((string) ($providerUser->account_type ?? '')) === 'provider';
        $isProvider = $providerUser && $company && $companyIsProvider && $userIsProvider;

        if (! $isProvider) {
            Log::warning('Partner API auth failed: API key linked to invalid provider context', [
                'provider_user_id' => $apiKey->provider_user_id,
                'api_key_id' => $apiKey->id,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API key is not linked to a valid provider company account.',
            ], 403);
        }

        if ($scope !== null && ! $apiKey->allows($scope)) {
            Log::warning('Partner API auth failed: API key missing required scope', [
                'provider_user_id' => $providerUser->id,
                'provider_company_id' => $providerUser->company->id,
                'api_key_id' => $apiKey->id,
                'required_scope' => $scope,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API key is not authorized for this resource.',
            ], 403);
        }

        if (! (bool) ($providerUser->company->is_open_api_enabled ?? false)) {
            Log::warning('Partner API auth failed: open API disabled for company', [
                'provider_user_id' => $providerUser->id,
                'provider_company_id' => $providerUser->company->id,
                'api_key_id' => $apiKey->id,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Open API access is disabled for this company.',
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

    /**
     * Company id for partner-chat rate limiting.
     *
     * Throttle runs before this middleware, so the limiter cannot read
     * provider_company yet. Active keys still map to their provider company.
     * Missing, invalid, and revoked keys return null and stay on the IP bucket.
     */
    public static function providerCompanyId(Request $request): ?int
    {
        $rawKey = self::extractApiKey($request);
        if (! $rawKey) {
            return null;
        }

        $apiKey = ProviderApiKey::with('providerUser.company')
            ->where('key_hash', hash('sha256', $rawKey))
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->first();

        $company = $apiKey?->providerUser?->company;
        if (! $company || strtolower((string) ($company->account_type ?? '')) !== 'provider') {
            return null;
        }

        return (int) $company->id;
    }

    private static function extractApiKey(Request $request): ?string
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
