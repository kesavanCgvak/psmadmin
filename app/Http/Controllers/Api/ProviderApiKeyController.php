<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProviderApiKeyController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$this->isProviderUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only provider users can manage API keys.',
                ], 403);
            }

            $keys = ProviderApiKey::where('provider_user_id', $user->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function (ProviderApiKey $key) {
                    return [
                        'id' => $key->id,
                        'name' => $key->name,
                        'key_prefix' => $key->key_prefix,
                        'is_active' => $key->is_active,
                        'created_at' => $key->created_at,
                        'last_used_at' => $key->last_used_at,
                        'expires_at' => $key->expires_at,
                        'revoked_at' => $key->revoked_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $keys,
            ]);
        } catch (\Exception $e) {
            Log::error('Provider API key listing failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch API keys.',
            ], 500);
        }
    }

    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$this->isProviderUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only provider users can generate API keys.',
                ], 403);
            }

            $plainApiKey = 'psm_pk_' . Str::random(48);
            $hash = hash('sha256', $plainApiKey);
            $keyPrefix = substr($plainApiKey, 0, 14);
            $name = $request->input('name') ?: 'Default key';

            DB::transaction(function () use ($user, $hash, $keyPrefix, $name) {
                ProviderApiKey::where('provider_user_id', $user->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'revoked_at' => now(),
                    ]);

                ProviderApiKey::create([
                    'provider_user_id' => $user->id,
                    'name' => $name,
                    'key_prefix' => $keyPrefix,
                    'key_hash' => $hash,
                    'is_active' => true,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'API key generated successfully. Save it now, it will not be shown again.',
                'data' => [
                    'api_key' => $plainApiKey,
                    'key_prefix' => $keyPrefix,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Provider API key generation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to generate API key.',
            ], 500);
        }
    }

    public function revoke(int $id): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            if (!$this->isProviderUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only provider users can revoke API keys.',
                ], 403);
            }

            $apiKey = ProviderApiKey::where('provider_user_id', $user->id)->find($id);
            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'API key not found.',
                ], 404);
            }

            if (!$apiKey->is_active) {
                return response()->json([
                    'success' => true,
                    'message' => 'API key is already inactive.',
                ]);
            }

            $apiKey->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'API key revoked successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Provider API key revoke failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to revoke API key.',
            ], 500);
        }
    }

    private function isProviderUser($user): bool
    {
        return (bool) ($user && $user->company && $user->company->account_type === 'provider');
    }
}

