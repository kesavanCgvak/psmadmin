<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\IntegrationValidationException;
use App\Http\Controllers\Controller;
use App\Models\CompanyIntegration;
use App\Services\Integrations\CompanyIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class IntegrationController extends Controller
{
    public function __construct(private readonly CompanyIntegrationService $integrationService)
    {
    }

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

            $normalizedType = strtolower((string) $request->input('integration_type'));
            if ($normalizedType === 'rentman' && !$request->filled('api_key') && $request->filled('auth_token')) {
                $request->merge(['api_key' => $request->input('auth_token')]);
            }
            $request->merge(['integration_type' => $normalizedType]);
            $validator = Validator::make(
                $request->all(),
                $this->integrationService->buildValidationRules($normalizedType)
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();
            $integration = $this->integrationService->upsert($companyId, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Integration credentials saved successfully.',
                'data' => $this->formatIntegrationResponse($integration),
            ], 200);

        } catch (IntegrationValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode(),
            ], $e->httpStatus());
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
                $usesApiKey = $this->integrationService->usesApiKey($integration_type);
                return response()->json([
                    'success' => true,
                    'data' => [
                        'integration_type' => $integration_type,
                        'api_base_url' => null,
                        'connected' => false,
                        'has_api_key' => $usesApiKey ? false : null,
                        'api_key_masked' => null,
                    ],
                ], 200);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatIntegrationResponse($integration),
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

    private function formatIntegrationResponse(CompanyIntegration $integration): array
    {
        $usesApiKey = $this->integrationService->usesApiKey($integration->integration_type);

        return [
            'integration_type' => $integration->integration_type,
            'api_base_url' => $integration->api_base_url,
            'connected' => $integration->isConnected(),
            'has_api_key' => $usesApiKey ? !empty($integration->api_key) : null,
            'api_key_masked' => $usesApiKey
                ? $this->maskSecretKeepingLastFour((string) $integration->api_key)
                : null,
        ];
    }
}
