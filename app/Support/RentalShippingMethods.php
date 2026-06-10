<?php

namespace App\Support;

final class RentalShippingMethods
{
    public const PICKUP = 'pickup';

    public const DELIVER_TO_ME = 'deliver_to_me';

    public const SHIP_TO_JOB_SITE = 'ship_to_job_site';

    public static function all(): array
    {
        return config('rental.shipping_methods', []);
    }

    public static function values(): array
    {
        return array_keys(self::all());
    }

    public static function default(): string
    {
        return config('rental.default_shipping_method', self::DELIVER_TO_ME);
    }

    public static function label(?string $method): string
    {
        if (!$method) {
            return 'N/A';
        }

        return self::all()[$method] ?? $method;
    }

    public static function addressRequired(?string $method): bool
    {
        return $method !== self::PICKUP;
    }

    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::values());
    }

    public static function optionsForApi(): array
    {
        return collect(self::all())
            ->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    public static function resolveDeliveryAddress(?string $shippingMethod, ?string $address): ?string
    {
        if (!self::addressRequired($shippingMethod)) {
            return null;
        }

        $address = trim((string) $address);

        return $address !== '' ? $address : null;
    }
}
