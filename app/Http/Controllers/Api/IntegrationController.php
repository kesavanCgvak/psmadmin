<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class IntegrationController extends Controller
{
    /**
     * Store or update API credentials for an integration.
     *
     * POST /api/integrations/store
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $companyId = $user->company_id ?? null;

            if (!$companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found for this user.',
                ], 403);
            }

            $rules = [
                'integration_type' => 'required|string|max:50|regex:/^[a-z0-9_-]+$/',
                'api_base_url' => 'required|string|url|max:500',
                'api_key' => 'nullable|string|max:1000',
                'client_id' => 'nullable|string|max:500',
                'client_secret' => 'nullable|string|max:1000',
            ];

            $existingIntegration = CompanyIntegration::where('company_id', $companyId)
                ->where('integration_type', $request->integration_type)
                ->first();

            if ($request->integration_type === 'flex') {
                // For existing Flex integrations, allow keeping the current API key.
                if (!$existingIntegration || empty($existingIntegration->api_key)) {
                    $rules['api_key'] = 'required|string|max:1000';
                }
            } else {
                $rules['client_id'] = 'required|string|max:500';
                $rules['client_secret'] = 'required|string|max:1000';
            }

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = [
                'api_base_url' => $request->api_base_url,
            ];

            if ($request->integration_type === 'flex') {
                if ($request->filled('api_key')) {
                    $data['api_key'] = $request->api_key;
                }
            } else {
                $data['client_id'] = $request->client_id;
                $data['client_secret'] = $request->client_secret;
            }

            $integration = CompanyIntegration::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'integration_type' => $request->integration_type,
                ],
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Integration credentials saved successfully.',
                'data' => [
                    'integration_type' => $integration->integration_type,
                    'api_base_url' => $integration->api_base_url,
                    'connected' => $integration->isConnected(),
                    'has_api_key' => $integration->integration_type === 'flex' ? !empty($integration->api_key) : null,
                    'api_key_masked' => $integration->integration_type === 'flex'
                        ? $this->maskSecretKeepingLastFour((string) $integration->api_key)
                        : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Integration store error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to save integration credentials.',
            ], 500);
        }
    }

    /**
     * Get integration configuration for the logged-in company.
     *
     * GET /api/integrations/{integration_type}
     */
    public function show(string $integration_type): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $companyId = $user->company_id ?? null;

            if (!$companyId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company not found for this user.',
                ], 403);
            }

            $integration = CompanyIntegration::where('company_id', $companyId)
                ->where('integration_type', $integration_type)
                ->first();

            if (!$integration) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'integration_type' => $integration_type,
                        'api_base_url' => null,
                        'connected' => false,
                        'has_api_key' => $integration_type === 'flex' ? false : null,
                        'api_key_masked' => null,
                    ],
                ], 200);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'integration_type' => $integration->integration_type,
                    'api_base_url' => $integration->api_base_url,
                    'connected' => $integration->isConnected(),
                    'has_api_key' => $integration->integration_type === 'flex' ? !empty($integration->api_key) : null,
                    'api_key_masked' => $integration->integration_type === 'flex'
                        ? $this->maskSecretKeepingLastFour((string) $integration->api_key)
                        : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Integration show error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch integration configuration.',
            ], 500);
        }
    }

    private function maskSecretKeepingLastFour(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($value, -4);
    }
}
