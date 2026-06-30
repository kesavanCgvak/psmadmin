<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RentalSoftwareCompanyLogo extends Model
{
    public const UPLOAD_DIR = 'images/rental_software_logos';

    protected $fillable = [
        'company_name',
        'logo_path',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public static function nextSortOrder(): int
    {
        $max = (int) static::query()->max('sort_order');

        return max(1, $max + 1);
    }

    public function getLogoUrlAttribute(): string
    {
        return str_starts_with($this->logo_path, 'http')
            ? $this->logo_path
            : url($this->logo_path);
    }
}
