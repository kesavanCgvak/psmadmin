<?php

namespace App\Support;

/**
 * Resolves company logo and profile image paths for API responses,
 * falling back to configured defaults when the stored value is missing
 * or the file does not exist under public/.
 */
final class DefaultImagePath
{
    public static function companyLogo(?string $path): string
    {
        return self::resolve($path, (string) config('images.default_company_logo'));
    }

    public static function profileImage(?string $path): string
    {
        return self::resolve($path, (string) config('images.default_profile_image'));
    }

    public static function companyLogoUrl(?string $path): string
    {
        return self::toUrl(self::companyLogo($path));
    }

    public static function profileImageUrl(?string $path): string
    {
        return self::toUrl(self::profileImage($path));
    }

    /**
     * Build the {path, url} payload used by company image endpoints.
     *
     * @return array{path: string, url: string}
     */
    public static function companyLogoPayload(?string $path): array
    {
        $resolved = self::companyLogo($path);

        return [
            'path' => $resolved,
            'url' => self::toUrl($resolved),
        ];
    }

    private static function resolve(?string $path, string $default): string
    {
        $path = is_string($path) ? trim($path) : '';

        if ($path !== '' && self::isAvailable($path)) {
            return $path;
        }

        return self::normalizeDefault($default);
    }

    private static function isAvailable(string $path): bool
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return true;
        }

        return is_file(public_path($path));
    }

    private static function normalizeDefault(string $default): string
    {
        $default = trim(str_replace('\\', '/', $default));

        if ($default === '') {
            return 'images/company_images/default_company_logo.png';
        }

        // Strip a leading "public/" if present in env/config values.
        if (str_starts_with($default, 'public/')) {
            $default = substr($default, strlen('public/'));
        }

        return ltrim($default, '/');
    }

    private static function toUrl(string $path): string
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : url($path);
    }
}
