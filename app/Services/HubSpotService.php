<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubSpotService
{
    protected string $baseUrl;
    protected ?string $accessToken;

    public function __construct()
    {
        $this->baseUrl = rtrim(Config::get('hubspot.base_url', 'https://api.hubapi.com'), '/');
        $this->accessToken = Config::get('hubspot.access_token');
    }

    /**
     * Check if HubSpot is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->accessToken);
    }

    /**
     * Check if a contact already exists in HubSpot by email.
     *
     * Returns:
     * - true  => contact exists
     * - false => contact does not exist
     * - null  => unknown (API failure or misconfiguration)
     *
     * @param  array<string, mixed>  $context  Optional tracing context (user_id, company_id, correlation_id, etc.)
     */
    public function contactExists(string $email, array $context = []): ?bool
    {
        $logContext = array_merge($context, [
            'email' => $email,
            'timestamp' => now()->toIso8601String(),
        ]);

        Log::info('Checking if HubSpot contact exists for email.', $logContext);

        if (!$this->isConfigured()) {
            Log::warning('HubSpot contact existence check skipped: missing access token.', array_merge($logContext, [
                'hubspot_configured' => false,
            ]));
            return null;
        }

        $path = '/crm/v3/objects/contacts/search';
        $fullUrl = $this->baseUrl . $path;
        $payload = [
            'filterGroups' => [
                [
                    'filters' => [
                        [
                            'propertyName' => 'email',
                            'operator' => 'EQ',
                            'value' => $email,
                        ],
                    ],
                ],
            ],
            'limit' => 1,
            'properties' => ['email'],
        ];

        Log::info('HubSpot API request - contact search.', array_merge($logContext, [
            'url' => $fullUrl,
            'payload' => $payload,
        ]));

        try {
            $response = Http::withToken($this->accessToken)
                ->baseUrl($this->baseUrl)
                ->post($path, $payload);

            $status = $response->status();
            $body = $response->body();

            Log::info('HubSpot API response - contact search.', array_merge($logContext, [
                'status' => $status,
                'body' => $body,
            ]));

            if ($response->failed()) {
                Log::error('HubSpot contact search failed.', array_merge($logContext, [
                    'url' => $fullUrl,
                    'payload' => $payload,
                    'status' => $status,
                    'body' => $body,
                ]));
                return null;
            }

            $data = $response->json();
            $results = $data['results'] ?? [];
            $exists = !empty($results);

            Log::info('HubSpot contact existence check result.', array_merge($logContext, [
                'exists' => $exists,
                'result_count' => is_array($results) ? count($results) : 0,
            ]));

            return $exists;
        } catch (\Throwable $e) {
            Log::error('HubSpot contact search exception.', array_merge($logContext, [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]));

            return null;
        }
    }

    /**
     * Create a new contact in HubSpot.
     *
     * On failure, logs the error and returns false.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context  Optional tracing context (user_id, company_id, correlation_id, etc.)
     */
    public function createContact(array $properties, array $context = []): bool
    {
        $logContext = array_merge($context, [
            'timestamp' => now()->toIso8601String(),
        ]);

        if (!$this->isConfigured()) {
            Log::warning('HubSpot contact creation skipped: missing access token.', array_merge($logContext, [
                'payload' => ['properties' => $properties],
                'hubspot_configured' => false,
            ]));
            return false;
        }

        $path = '/crm/v3/objects/contacts';
        $fullUrl = $this->baseUrl . $path;
        $payload = ['properties' => $properties];

        Log::info('HubSpot API request - create contact.', array_merge($logContext, [
            'url' => $fullUrl,
            'payload' => $payload,
        ]));

        try {
            $response = Http::withToken($this->accessToken)
                ->baseUrl($this->baseUrl)
                ->post($path, $payload);

            $status = $response->status();
            $body = $response->body();

            Log::info('HubSpot API response - create contact.', array_merge($logContext, [
                'status' => $status,
                'body' => $body,
            ]));

            if ($response->failed()) {
                Log::error('HubSpot contact creation failed.', array_merge($logContext, [
                    'url' => $fullUrl,
                    'payload' => $payload,
                    'status' => $status,
                    'body' => $body,
                    'final_status' => 'failed',
                ]));
                return false;
            }

            Log::info('HubSpot contact created successfully.', array_merge($logContext, [
                'payload' => $payload,
                'hubspot_response' => $response->json(),
                'status' => $status,
                'final_status' => 'success',
            ]));

            return true;
        } catch (\Throwable $e) {
            Log::error('HubSpot contact creation exception.', array_merge($logContext, [
                'payload' => $payload,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'final_status' => 'failed_exception',
            ]));

            return false;
        }
    }
}
