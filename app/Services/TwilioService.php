<?php

namespace App\Services;

use App\Contracts\SmsProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwilioService implements SmsProvider
{
    protected string $baseUrl = 'https://api.twilio.com/2010-04-01';

    protected ?string $sid;

    protected ?string $token;

    protected ?string $from;

    public function __construct()
    {
        $this->sid = config('services.twilio.sid');
        $this->token = config('services.twilio.token');
        $this->from = config('services.twilio.from');
    }

    /**
     * Check if Twilio is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->sid) && !empty($this->token) && !empty($this->from);
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
     * Format phone number to E.164 (with leading + as Twilio requires),
     * e.g. +14155552671.
     */
    public function formatPhoneForApi(string $mobile): string
    {
        $trimmed = trim($mobile);
        $digits = preg_replace('/\D/', '', $trimmed);

        // Assume US/Canada if 10 digits, prepend country code 1.
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        return '+' . $digits;
    }

    /**
     * Send an SMS via the Twilio REST API.
     *
     * @param  string  $to  Phone number (will be formatted to E.164)
     * @param  string  $text  Message content
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function sendSms(string $to, string $text): array
    {
        if (!$this->isConfigured()) {
            Log::warning('Twilio SMS skipped: missing credentials.');

            return ['success' => false, 'error' => 'Twilio not configured'];
        }

        if (!$this->isValidMobile($to)) {
            Log::warning('Twilio SMS skipped: invalid mobile number.', [
                'to' => substr($to, 0, 4) . '***',
            ]);

            return ['success' => false, 'error' => 'Invalid mobile number'];
        }

        $phone = $this->formatPhoneForApi($to);
        $url = $this->baseUrl . '/Accounts/' . $this->sid . '/Messages.json';

        Log::debug('Twilio SMS: dispatching request to API.', [
            'endpoint' => $url,
            'from' => $this->from,
            'to_preview' => substr($phone, 0, 4) . '***',
            'text_length' => strlen($text),
        ]);

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->sid, $this->token)
                ->timeout(15)
                ->post($url, [
                    'To' => $phone,
                    'From' => $this->from,
                    'Body' => $text,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $messageId = $data['sid'] ?? null;

                Log::info('Twilio SMS sent successfully.', [
                    'message_id' => $messageId,
                    'phones_preview' => substr($phone, 0, 4) . '***',
                ]);

                return ['success' => true, 'message_id' => $messageId];
            }

            Log::error('Twilio SMS API failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'phones_preview' => substr($phone, 0, 4) . '***',
            ]);

            return [
                'success' => false,
                'error' => $response->body() ?: 'Unknown API error',
            ];
        } catch (\Throwable $e) {
            Log::error('Twilio SMS exception.', [
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
