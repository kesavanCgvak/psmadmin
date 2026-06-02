<?php

namespace App\Services\Integrations;

use App\Exceptions\IntegrationValidationException;
use App\Jobs\SyncRentmanEquipmentJob;
use App\Models\CompanyIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CompanyIntegrationService
{
    private const SUPPORTED_TYPES = [
        'flex' => [
            'requires_api_base_url' => true,
            'uses_api_key' => true,
        ],
        'rentman' => [
            'requires_api_base_url' => false,
            'uses_api_key' => true,
        ],
    ];

    public static function supportedTypes(): array
    {
        return array_keys(self::SUPPORTED_TYPES);
    }

    public function buildValidationRules(string $integrationType): array
    {
        $typeConfig = self::SUPPORTED_TYPES[$integrationType] ?? null;

        $apiBaseUrlRule = [
            Rule::requiredIf(($typeConfig['requires_api_base_url'] ?? false) === true),
            'nullable',
            'string',
            'url',
            'max:500',
        ];

        $apiKeyRule = [
            Rule::requiredIf(($typeConfig['uses_api_key'] ?? false) === true),
            'nullable',
            'string',
            'max:1000',
        ];

        return [
            'integration_type' => ['required', 'string', Rule::in(self::supportedTypes())],
            'api_base_url' => $apiBaseUrlRule,
            'api_key' => $apiKeyRule,
            'settings' => ['nullable', 'array'],
            'client_id' => ['nullable', 'string', 'max:500'],
            'client_secret' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function upsert(int $companyId, array $validatedData): CompanyIntegration
    {
        $integrationType = strtolower((string) $validatedData['integration_type']);
        $typeConfig = self::SUPPORTED_TYPES[$integrationType];

        $this->validateConnection($integrationType, $validatedData);

        $existingDifferentSource = CompanyIntegration::query()
            ->where('company_id', $companyId)
            ->where('integration_type', '!=', $integrationType);

        $data = [
            'api_base_url' => ($typeConfig['requires_api_base_url'] ?? false)
                ? ($validatedData['api_base_url'] ?? null)
                : $this->resolveOptionalBaseUrl($integrationType, $validatedData),
            'settings' => $validatedData['settings'] ?? null,
        ];

        if (($typeConfig['uses_api_key'] ?? false) && array_key_exists('api_key', $validatedData)) {
            $data['api_key'] = $validatedData['api_key'];
        }

        $integration = DB::transaction(function () use ($existingDifferentSource, $companyId, $integrationType, $data) {
            $existingDifferentSource->delete();

            return CompanyIntegration::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'integration_type' => $integrationType,
                ],
                $data
            );
        });

        if ($integrationType === 'rentman') {
            Log::info('Running Rentman equipment sync immediately after integration save.', [
                'company_id' => $companyId,
                'queue_connection' => config('queue.default'),
            ]);
            SyncRentmanEquipmentJob::dispatchSync($companyId);
        }

        return $integration;
    }

    public function usesApiKey(string $integrationType): bool
    {
        return (self::SUPPORTED_TYPES[$integrationType]['uses_api_key'] ?? false) === true;
    }

    private function resolveOptionalBaseUrl(string $integrationType, array $validatedData): ?string
    {
        if ($integrationType === 'rentman') {
            return rtrim((string) config('services.rentman.base_url', ''), '/');
        }

        return $validatedData['api_base_url'] ?? null;
    }

    private function validateConnection(string $integrationType, array $validatedData): void
    {
        if ($integrationType === 'flex') {
            $this->validateFlex($validatedData);

            return;
        }

        if ($integrationType === 'rentman') {
            $this->validateRentman($validatedData);
        }
    }

    private function validateFlex(array $validatedData): void
    {
        $baseUrl = rtrim((string) ($validatedData['api_base_url'] ?? ''), '/');
        $apiKey = (string) ($validatedData['api_key'] ?? '');
        $requestUrl = $baseUrl . '/f5/api/contact?page=0&size=20';

        try {
            $response = Http::timeout(15)
                ->withHeaders(array_merge($this->flexAuthHeaders($apiKey), [
                    'Accept' => '*/*',
                    'Content-Type' => 'application/json',
                ]))
                ->get($requestUrl);

            if (!$response->successful()) {
                $this->logValidationFailure('flex', $requestUrl, $response->status(), 'Flex test request failed');

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new IntegrationValidationException('Invalid Flex API key.', 422, 'INVALID_API_KEY');
                }

                if ($response->status() === 404) {
                    throw new IntegrationValidationException('Invalid Flex base URL or endpoint not found.', 422, 'INVALID_BASE_URL');
                }

                throw new IntegrationValidationException('Unable to validate Flex integration credentials.', 422, 'VALIDATION_FAILED');
            }
        } catch (ConnectionException $e) {
            $this->logValidationFailure('flex', $requestUrl, null, $e->getMessage());
            throw new IntegrationValidationException('Flex validation failed due to network/timeout issue.', 422, 'NETWORK_TIMEOUT');
        } catch (\Throwable $e) {
            if ($e instanceof IntegrationValidationException) {
                throw $e;
            }

            $this->logValidationFailure('flex', $requestUrl, null, $e->getMessage());
            throw new IntegrationValidationException('Unable to validate Flex integration credentials.', 422, 'VALIDATION_FAILED');
        }
    }

    private function validateRentman(array $validatedData): void
    {
        $baseUrl = rtrim((string) config('services.rentman.base_url', ''), '/');
        $authToken = (string) ($validatedData['api_key'] ?? '');

        if ($baseUrl === '') {
            throw new IntegrationValidationException('Rentman base URL is not configured.', 500, 'MISSING_BASE_URL');
        }

        $requestUrl = $baseUrl . '/equipment';

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $authToken,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->get($requestUrl, ['limit' => 1]);

            if (!$response->successful()) {
                $this->logValidationFailure('rentman', $requestUrl, $response->status(), 'Rentman test request failed');

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new IntegrationValidationException('Invalid Rentman auth token.', 422, 'INVALID_AUTH_TOKEN');
                }

                if ($response->status() === 404) {
                    throw new IntegrationValidationException('Invalid Rentman base URL or endpoint not found.', 422, 'INVALID_BASE_URL');
                }

                throw new IntegrationValidationException('Unable to validate Rentman integration credentials.', 422, 'VALIDATION_FAILED');
            }
        } catch (ConnectionException $e) {
            $this->logValidationFailure('rentman', $requestUrl, null, $e->getMessage());
            throw new IntegrationValidationException('Rentman validation failed due to network/timeout issue.', 422, 'NETWORK_TIMEOUT');
        } catch (\Throwable $e) {
            if ($e instanceof IntegrationValidationException) {
                throw $e;
            }

            $this->logValidationFailure('rentman', $requestUrl, null, $e->getMessage());
            throw new IntegrationValidationException('Unable to validate Rentman integration credentials.', 422, 'VALIDATION_FAILED');
        }
    }

    /**
     * @return array<string, string>
     */
    private function flexAuthHeaders(string $apiKey): array
    {
        $authType = config('flex.auth_header', 'x_auth');
        if ($authType === 'x_auth') {
            return ['X-Auth-Token' => $apiKey];
        }

        return ['Authorization' => 'Bearer ' . $apiKey];
    }

    private function logValidationFailure(
        string $integrationType,
        string $requestUrl,
        ?int $statusCode,
        string $errorMessage
    ): void {
        Log::warning('Integration validation failed', [
            'integration_type' => $integrationType,
            'request_url' => $requestUrl,
            'response_status' => $statusCode,
            'error' => $errorMessage,
        ]);
    }
}
