<?php

namespace App\Services;

use App\Contracts\SmsProviderInterface;

class NullSmsService implements SmsProviderInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function isValidMobile(?string $mobile): bool
    {
        if (empty($mobile) || !is_string($mobile)) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $mobile);

        return strlen($digits) >= 10;
    }

    public function sendSms(string $to, string $text): array
    {
        return ['success' => false, 'error' => 'No SMS provider configured'];
    }
}
