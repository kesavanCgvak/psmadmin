<?php

namespace App\Contracts;

interface SmsProviderInterface
{
    public function isConfigured(): bool;

    public function isValidMobile(?string $mobile): bool;

    /**
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function sendSms(string $to, string $text): array;
}
