<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalSoftwareCompanyLogo extends Model
{
    public const UPLOAD_DIR = 'images/rental_software_logos';

    protected $fillable = [
        'company_name',
        'logo_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getLogoUrlAttribute(): string
    {
        return str_starts_with($this->logo_path, 'http')
            ? $this->logo_path
            : url($this->logo_path);
    }
}
