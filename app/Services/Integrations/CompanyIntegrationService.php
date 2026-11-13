<?php

namespace App\Services\Integrations;

use App\Exceptions\IntegrationValidationException;
use App\Jobs\SyncRentmanEquipmentJob;
use App\Models\CompanyIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CompanyIntegrationService
{
    /**
     * Plain-text messages from https://myhirehop.com/lang/en-US.js (HTML removed).
     * Unknown codes are reported by number only.
     *
     * @var array<int, string>
     */
    private const HIREHOP_ERROR_MESSAGES = [
        0 => 'Database error, please contact the HireHop team to report this.',
        1 => 'Connection error, please try again.',
        2 => 'User not authorized to perform task.',
        3 => 'Missing parameters',
        9 => 'You are not logged in. Please login again.',
    ];

    private ?array $validatedHireHopAccount = null;

    private const SUPPORTED_TYPES = [
        'flex' => [
            'requires_api_base_url' => true,
            'uses_api_key' => true,
        ],
        'rentman' => [
            'requires_api_base_url' => false,
            'uses_api_key' => true,
        ],
        'hirehop' => [
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
            'client_id' => ['nullable', 'string', 'max:500'],
            'client_secret' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function upsert(int $companyId, array $validatedData): CompanyIntegration
    {
        $this->validatedHireHopAccount = null;
        $integrationType = strtolower((string) $validatedData['integration_type']);
        $typeConfig = self::SUPPORTED_TYPES[$integrationType];

        $this->validateConnection($integrationType, $validatedData);

        $data = [
            'api_base_url' => ($typeConfig['requires_api_base_url'] ?? false)
                ? ($validatedData['api_base_url'] ?? null)
                : $this->resolveOptionalBaseUrl($integrationType, $validatedData),
        ];

        if (($typeConfig['uses_api_key'] ?? false) && array_key_exists('api_key', $validatedData)) {
            $data['api_key'] = $validatedData['api_key'];
        }

        $integration = DB::transaction(function () use ($companyId, $integrationType, $data) {
            // Soft-delete through the model so the audit trail records the previous
            // integration. Attributes are read raw, so a stale encrypted api_key is
            // not decrypted (updateOrCreate would throw "The MAC is invalid").
            CompanyIntegration::query()
                ->where('company_id', $companyId)
                ->get()
                ->each(function (CompanyIntegration $existing) {
                    $existing->delete();
                });

            return CompanyIntegration::create(array_merge([
                'company_id' => $companyId,
                'integration_type' => $integrationType,
            ], $data));
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

    /**
     * Account details from the last successful HireHop token check in this request.
     * They are not stored on the integration row.
     *
     * @return array<string, mixed>|null
     */
    public function validatedHireHopAccount(): ?array
    {
        return $this->validatedHireHopAccount;
    }

    private function resolveOptionalBaseUrl(string $integrationType, array $validatedData): ?string
    {
        if ($integrationType === 'rentman') {
            return rtrim((string) config('services.rentman.base_url', ''), '/');
        }

        if ($integrationType === 'hirehop') {
            return rtrim((string) config('hirehop.base_url', ''), '/');
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

            return;
        }

        if ($integrationType === 'hirehop') {
            $this->validateHireHop($validatedData);
        }
    }

    private function validateFlex(array $validatedData): void
    {
        $baseUrl = rtrim((string) ($validatedData['api_base_url'] ?? ''), '/');
        $apiKey = (string) ($validatedData['api_key'] ?? '');
        $requestUrl = $baseUrl.'/f5/api/contact?page=0&size=20';

        try {
            $response = Http::timeout(15)
                ->withHeaders(array_merge($this->flexAuthHeaders($apiKey), [
                    'Accept' => '*/*',
                    'Content-Type' => 'application/json',
                ]))
                ->get($requestUrl);

            if (! $response->successful()) {
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

        $requestUrl = $baseUrl.'/equipment';

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$authToken,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->get($requestUrl, ['limit' => 1]);

            if (! $response->successful()) {
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

        return ['Authorization' => 'Bearer '.$apiKey];
    }

    private function validateHireHop(array $validatedData): void
    {
        $baseUrl = rtrim((string) config('hirehop.base_url', ''), '/');
        $apiKey = (string) ($validatedData['api_key'] ?? '');

        if ($baseUrl === '') {
            throw new IntegrationValidationException('HireHop base URL is not configured.', 500, 'MISSING_BASE_URL');
        }

        if ($apiKey === '' || preg_match('/[\r\n\0]/', $apiKey) === 1) {
            throw new IntegrationValidationException('Invalid HireHop API token.', 422, 'INVALID_API_TOKEN');
        }

        $path = '/'.ltrim((string) config('hirehop.user_info_path', '/php_functions/get_user_info.php'), '/');
        $requestUrl = $baseUrl.$path;
        $timeout = (int) config('hirehop.timeout', 15);
        if ($timeout < 1) {
            $timeout = 15;
        }

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'X-TOKEN' => $apiKey,
                    'Accept' => 'application/json',
                ])
                ->get($requestUrl);

            $payload = $this->decodeHireHopPayload($response);
            $error = is_array($payload) ? $this->hireHopErrorValue($payload) : null;

            if ($error !== null) {
                [$message, $errorCode] = $this->hireHopErrorPresentation($error, $apiKey);
                if ($response->status() === 401 || $response->status() === 403) {
                    $errorCode = 'INVALID_API_TOKEN';
                }

                $this->logValidationFailure('hirehop', $requestUrl, $response->status(), $message);
                throw new IntegrationValidationException($message, 422, $errorCode);
            }

            if (! $response->successful()) {
                $this->logValidationFailure('hirehop', $requestUrl, $response->status(), 'HireHop test request failed');

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new IntegrationValidationException('Invalid HireHop API token.', 422, 'INVALID_API_TOKEN');
                }

                if ($response->status() === 404) {
                    throw new IntegrationValidationException('HireHop user information endpoint was not found.', 422, 'INVALID_BASE_URL');
                }

                throw new IntegrationValidationException('Unable to validate HireHop integration credentials.', 422, 'VALIDATION_FAILED');
            }

            if (! is_array($payload) || ! $this->hireHopAccountIsComplete($payload)) {
                $this->logValidationFailure('hirehop', $requestUrl, $response->status(), 'HireHop response was missing account details');
                throw new IntegrationValidationException('HireHop returned an unexpected response.', 422, 'INVALID_RESPONSE');
            }

            $this->validatedHireHopAccount = $this->hireHopAccountFromPayload($payload);
        } catch (ConnectionException $e) {
            $this->logValidationFailure(
                'hirehop',
                $requestUrl,
                null,
                $this->redactSecret($e->getMessage(), $apiKey)
            );
            throw new IntegrationValidationException('HireHop validation failed due to network/timeout issue.', 422, 'NETWORK_TIMEOUT');
        } catch (\Throwable $e) {
            if ($e instanceof IntegrationValidationException) {
                throw $e;
            }

            $this->logValidationFailure(
                'hirehop',
                $requestUrl,
                null,
                $this->redactSecret($e->getMessage(), $apiKey)
            );
            throw new IntegrationValidationException('Unable to validate HireHop integration credentials.', 422, 'VALIDATION_FAILED');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeHireHopPayload(Response $response): ?array
    {
        $body = trim($response->body());
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hireHopErrorValue(array $payload): mixed
    {
        if (! array_key_exists('error', $payload) || $payload['error'] === null || $payload['error'] === '') {
            return null;
        }

        return $payload['error'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hireHopAccountIsComplete(array $payload): bool
    {
        foreach (['NAME', 'EMAIL', 'ID', 'COMPANY_ID'] as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                return false;
            }

            if (! is_scalar($payload[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function hireHopAccountFromPayload(array $payload): array
    {
        $currency = is_array($payload['CURRENCY'] ?? null) ? $payload['CURRENCY'] : [];

        return [
            'name' => (string) $payload['NAME'],
            'email' => (string) $payload['EMAIL'],
            'id' => $payload['ID'],
            'company_id' => $payload['COMPANY_ID'],
            'default_depot' => array_key_exists('DEFAULT_DEPOT', $payload) ? $payload['DEFAULT_DEPOT'] : null,
            'currency_code' => isset($currency['CODE']) && is_scalar($currency['CODE']) ? (string) $currency['CODE'] : null,
            'currency_symbol' => isset($currency['SYMBOL']) && is_scalar($currency['SYMBOL']) ? (string) $currency['SYMBOL'] : null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function hireHopErrorPresentation(mixed $error, string $secret): array
    {
        if (is_int($error) || (is_string($error) && preg_match('/^-?\d+$/', $error) === 1)) {
            $code = (int) $error;
            $known = self::HIREHOP_ERROR_MESSAGES[$code] ?? null;
            $message = $known === null
                ? "HireHop rejected the API token (error {$code})."
                : "HireHop rejected the API token (error {$code}: {$known}).";

            return [$message, $code === 9 ? 'INVALID_API_TOKEN' : 'HIREHOP_ERROR'];
        }

        if (is_string($error)) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($error)) ?? '');
            $text = $this->redactSecret($text, $secret);
            if ($text === '') {
                return ['HireHop rejected the API token.', 'HIREHOP_ERROR'];
            }

            if (strlen($text) > 300) {
                $text = substr($text, 0, 300);
            }

            return ["HireHop rejected the API token: {$text}", 'HIREHOP_ERROR'];
        }

        return ['HireHop rejected the API token.', 'HIREHOP_ERROR'];
    }

    private function redactSecret(string $message, string $secret): string
    {
        if ($secret === '') {
            return $message;
        }

        return str_replace($secret, '[redacted]', $message);
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
