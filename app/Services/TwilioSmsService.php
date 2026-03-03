<?php

namespace App\Services;

use App\Contracts\SmsProviderInterface;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class TwilioSmsService implements SmsProviderInterface
{
    protected ?Client $client = null;

    public function __construct()
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        if ($sid && $token) {
            $this->client = new Client($sid, $token);
        }
    }

    public function isConfigured(): bool
    {
        return $this->client !== null
            && ! empty(config('services.twilio.from'));
    }

    public function isValidMobile(?string $mobile): bool
    {
        if (empty($mobile) || ! is_string($mobile)) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $mobile);

        return strlen($digits) >= 10;
    }

    /**
     * Format phone to E.164 (Twilio expects +1234567890).
     */
    protected function formatPhoneForApi(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile);
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return '+'.$digits;
    }

    public function sendSms(string $to, string $text): array
    {
        if (! $this->isConfigured()) {
            Log::warning('Twilio SMS skipped: missing credentials.');

            return ['success' => false, 'error' => 'Twilio not configured'];
        }

        if (! $this->isValidMobile($to)) {
            Log::warning('Twilio SMS skipped: invalid mobile number.', [
                'to' => substr($to, 0, 4).'***',
            ]);

            return ['success' => false, 'error' => 'Invalid mobile number'];
        }

        $toFormatted = $this->formatPhoneForApi($to);
        $from = config('services.twilio.from');

        try {
            $message = $this->client->messages->create($toFormatted, [
                'body' => $text,
                'from' => $from,
            ]);

            Log::info('Twilio SMS sent successfully.', [
                'message_sid' => $message->sid,
                'phones_preview' => substr($toFormatted, 0, 5).'***',
            ]);

            return ['success' => true, 'message_id' => $message->sid];
        } catch (\Twilio\Exceptions\RestException $e) {
            Log::error('Twilio SMS API failed.', [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'phones_preview' => substr($toFormatted, 0, 5).'***',
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('Twilio SMS exception.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
