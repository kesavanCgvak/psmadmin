<?php

namespace App\Services\Integrations;

use App\Models\CompanyIntegration;

class FlexService
{
    protected CompanyIntegration $integration;

    public function __construct(CompanyIntegration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * Get a valid access token for the Flex API.
     * Will use existing token if valid, otherwise refresh.
     *
     * @return string|null Access token or null if unavailable
     */
    public function getAccessToken(): ?string
    {
        // TODO: Implement token retrieval logic
        // - Check if token_expires_at is still valid (with buffer)
        // - If expired, call refreshAccessToken()
        // - Return access_token
        return null;
    }

    /**
     * Refresh the access token using the refresh token.
     *
     * @return string|null New access token or null on failure
     */
    public function refreshAccessToken(): ?string
    {
        // TODO: Implement token refresh logic
        // - Call Flex OAuth token endpoint with refresh_token
        // - Update integration record with new access_token, refresh_token, token_expires_at
        // - Return new access_token
        return null;
    }
}
