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
     */
    public function contactExists(string $email): ?bool
    {
        Log::info('Checking if HubSpot contact exists for email.', [
            'email' => $email,
        ]);

        if (!$this->isConfigured()) {
            Log::warning('HubSpot contact existence check skipped: missing access token.');
            return null;
        }

        $path = '/crm/v3/objects/contacts/search';
        Log::info('HubSpot API request - contact search.', [
            'url' => $this->baseUrl . $path,
        ]);
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

        Log::info('HubSpot API request - contact search.', [
            'url' => $fullUrl,
            'payload' => $payload,
        ]);

        try {
            $response = Http::withToken($this->accessToken)
                ->baseUrl($this->baseUrl)
                ->post($path, $payload);

            if ($response->failed()) {
                Log::error('HubSpot contact search failed.', [
                    'url' => $fullUrl,
                    'payload' => $payload,
                    'email' => $email,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $results = $data['results'] ?? [];

            return !empty($results);
        } catch (\Throwable $e) {
            Log::error('HubSpot contact search exception.', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a new contact in HubSpot.
     *
     * On failure, logs the error and returns false.
     */
    public function createContact(array $properties): bool
    {
        if (!$this->isConfigured()) {
            Log::warning('HubSpot contact creation skipped: missing access token.', [
                'properties' => $properties,
            ]);
            return false;
        }

        $path = '/crm/v3/objects/contacts';
        $fullUrl = $this->baseUrl . $path;
        $payload = ['properties' => $properties];

        Log::info('HubSpot API request - create contact.', [
            'url' => $fullUrl,
            'payload' => $payload,
        ]);

        try {
            $response = Http::withToken($this->accessToken)
                ->baseUrl($this->baseUrl)
                ->post($path, $payload);

            if ($response->failed()) {
                Log::error('HubSpot contact creation failed.', [
                    'url' => $fullUrl,
                    'payload' => $payload,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            Log::info('HubSpot contact created successfully.', [
                'properties' => $properties,
                'hubspot_response' => $response->json(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('HubSpot contact creation exception.', [
                'properties' => $properties,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

