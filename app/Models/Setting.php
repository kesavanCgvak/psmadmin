<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
    ];

    /**
     * Get a setting value by key
     */
    public static function get(string $key, $default = null)
    {
        return Cache::remember("setting.{$key}", 3600, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();
            
            if (!$setting) {
                return $default;
            }
            
            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Set a setting value by key
     */
    public static function set(string $key, $value, string $type = 'string', string $description = null): bool
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            [
                'value' => self::formatValue($value, $type),
                'type' => $type,
                'description' => $description ?? self::where('key', $key)->value('description'),
            ]
        );
        
        // Clear cache
        Cache::forget("setting.{$key}");
        
        return true;
    }

    /**
     * Check if payment is enabled
     */
    public static function isPaymentEnabled(): bool
    {
        return (bool) self::get('payment_enabled', true); // Default to enabled
    }

    /**
     * Enable payment
     */
    public static function enablePayment(): bool
    {
        return self::set('payment_enabled', true, 'boolean');
    }

    /**
     * Disable payment
     */
    public static function disablePayment(): bool
    {
        return self::set('payment_enabled', false, 'boolean');
    }

    /**
     * Get company user limit
     */
    public static function getCompanyUserLimit(): int
    {
        return (int) self::get('company_user_limit', 3); // Default to 3
    }

    /**
     * Set company user limit
     */
    public static function setCompanyUserLimit(int $limit): bool
    {
        return self::set('company_user_limit', $limit, 'integer', 'Maximum number of users allowed per company');
    }

    /**
     * Cast value based on type
     */
    private static function castValue($value, string $type)
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Format value based on type before storing
     */
    private static function formatValue($value, string $type)
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) $value,
            'float' => (string) $value,
            'json' => json_encode($value),
            default => (string) $value,
        };
    }
}


