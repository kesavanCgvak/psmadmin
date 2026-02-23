<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextMagicService
{
    protected string $baseUrl = 'https://rest.textmagic.com/api/v2';

    protected ?string $username;

    protected ?string $apiKey;

    public function __construct()
    {
        $this->username = config('services.textmagic.username');
        $this->apiKey = config('services.textmagic.key');
    }

    /**
     * Check if TextMagic is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->username) && !empty($this->apiKey);
    }

    /**
     * Format phone number to E.164 international format (digits only, no leading +).
     * TextMagic expects: 447860021130 (no + sign).
     */
    public function formatPhoneForApi(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile);

        // Assume US/Canada if 10 digits, prepend 1
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        return $digits;
    }

    /**
     * Validate that a mobile number has at least 10 digits.
     */
    public function isValidMobile(?string $mobile): bool
    {
        if (empty($mobile) || !is_string($mobile)) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $mobile);

        return strlen($digits) >= 10;
    }

    /**
     * Send an SMS via TextMagic API.
     *
     * @param  string  $to  Phone number (will be formatted to E.164)
     * @param  string  $text  Message content
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function sendSms(string $to, string $text): array
    {
        if (!$this->isConfigured()) {
            Log::warning('TextMagic SMS skipped: missing credentials.');

            return ['success' => false, 'error' => 'TextMagic not configured'];
        }

        if (!$this->isValidMobile($to)) {
            Log::warning('TextMagic SMS skipped: invalid mobile number.', [
                'to' => substr($to, 0, 4) . '***',
            ]);

            return ['success' => false, 'error' => 'Invalid mobile number'];
        }

        $phones = $this->formatPhoneForApi($to);
        $url = $this->baseUrl . '/messages';

        try {
            $response = Http::withHeaders([
                'X-TM-Username' => $this->username,
                'X-TM-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($url, [
                'text' => $text,
                'phones' => $phones,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $messageId = $data['messageId'] ?? $data['id'] ?? null;

                Log::info('TextMagic SMS sent successfully.', [
                    'message_id' => $messageId,
                    'phones_preview' => substr($phones, 0, 4) . '***',
                ]);

                return ['success' => true, 'message_id' => $messageId];
            }

            Log::error('TextMagic SMS API failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'phones_preview' => substr($phones, 0, 4) . '***',
            ]);

            return [
                'success' => false,
                'error' => $response->body() ?: 'Unknown API error',
            ];
        } catch (\Throwable $e) {
            Log::error('TextMagic SMS exception.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
