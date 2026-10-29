<?php

namespace App\Support;

/**
 * Holds the template and sanitized data for the email currently being sent.
 * The logging listener reads it during MessageSending. It is not a header,
 * so the values are not copied onto the outbound message.
 */
class EmailLogContext
{
    /**
     * Templates whose rendered body contains a password or a one-time link.
     *
     * @var list<string>
     */
    public const SENSITIVE_TEMPLATES = [
        'forgotPassword',
        'verificationEmail',
        'registrationSuccess',
        'new-admin-user',
    ];

    /**
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'reset_url',
        'verify_url',
        'remember_token',
        'api_key',
        'secret',
        'authorization',
        'smtp_password',
        'mail_password',
    ];

    /**
     * @var array{template: string, data: array<string, mixed>, sensitive: bool}|null
     */
    private static ?array $current = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function begin(string $template, array $data): void
    {
        $sanitized = self::sanitize($data);

        self::$current = [
            'template' => $template,
            'data' => $sanitized,
            'sensitive' => in_array($template, self::SENSITIVE_TEMPLATES, true) || self::dataHasSecret($data),
        ];
    }

    /**
     * @return array{template: string, data: array<string, mixed>, sensitive: bool}|null
     */
    public static function current(): ?array
    {
        return self::$current;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    public static function looksSensitive(?string $subject, ?string $html, ?string $text): bool
    {
        $blob = strtolower(trim(($subject ?? '').' '.($html ?? '').' '.($text ?? '')));

        if ($blob === '') {
            return false;
        }

        foreach ([
            'password reset',
            'reset password',
            'your password',
            'temporary password',
            'email verification',
            'verify your email',
            'confirm your email',
        ] as $phrase) {
            if (str_contains($blob, $phrase)) {
                return true;
            }
        }

        return (bool) preg_match('/[?&](?:token|signature)=/i', $blob);
    }

    public static function isRateLimitError(string $message): bool
    {
        $haystack = strtolower($message);

        return str_contains($haystack, 'max emails per hour')
            || str_contains($haystack, 'hourly email')
            || (str_contains($haystack, 'rate limit') && str_contains($haystack, 'mail'))
            || str_contains($haystack, 'too many emails')
            || (str_contains($haystack, 'exceeded') && str_contains($haystack, 'per hour') && str_contains($haystack, 'message discarded'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);
            if (in_array($name, self::SENSITIVE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitize($value, $depth + 1);

                continue;
            }

            if (is_string($value)) {
                $clean[$key] = self::redactString($value);

                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function dataHasSecret(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                return true;
            }

            if (is_array($value) && self::dataHasSecret($value)) {
                return true;
            }
        }

        return false;
    }

    private static function redactString(string $value): string
    {
        $redacted = preg_replace('/([?&](?:token|signature)=)[^&\s]+/i', '$1redacted', $value);

        return is_string($redacted) ? $redacted : $value;
    }
}
