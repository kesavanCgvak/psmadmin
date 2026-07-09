<?php

namespace App\Contracts;

interface SmsProvider
{
    /**
     * Whether the provider has all required credentials configured.
     */
    public function isConfigured(): bool;

    /**
     * Validate that a mobile number is plausibly sendable (>= 10 digits).
     */
    public function isValidMobile(?string $mobile): bool;

    /**
     * Send an SMS.
     *
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function sendSms(string $to, string $text): array;
}
