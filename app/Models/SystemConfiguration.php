<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SystemConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'category',
        'label',
        'value',
        'description',
        'type',
        'options',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
    ];

    /**
     * Get configuration value by key
     */
    public static function getValue(string $key, $default = null)
    {
        $config = self::where('key', $key)->first();
        return $config ? $config->value : $default;
    }

    /**
     * Set configuration value by key
     */
    public static function setValue(string $key, $value): bool
    {
        $config = self::where('key', $key)->first();
        if ($config) {
            $config->value = $value;
            return $config->save();
        }
        return false;
    }

    /**
     * Get all configurations grouped by category
     */
    public static function getByCategory(): array
    {
        return self::orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category')
            ->toArray();
    }
}
