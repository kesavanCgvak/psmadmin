<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\NormalizesName;

class Region extends Model
{
    use HasFactory, NormalizesName;

    protected $fillable = ['name', 'normalized_name'];

    /**
     * A region has many countries.
     */
    public function countries()
    {
        return $this->hasMany(Country::class);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($region) {
            if ($region->isDirty('name')) {
                $region->normalized_name = self::normalizeNameStatic($region->name);
            }
        });
    }

    /**
     * Check if a region with the same normalized name already exists.
     * 
     * @param string $name
     * @param int|null $excludeId Exclude this ID from the check (for updates)
     * @return bool
     */
    public static function isDuplicate(string $name, ?int $excludeId = null): bool
    {
        $normalizedName = self::normalizeNameStatic($name);
        
        $query = self::where('normalized_name', $normalizedName);
        
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->exists();
    }
}
