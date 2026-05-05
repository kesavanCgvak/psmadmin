<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentmanEquipment extends Model
{
    protected $table = 'rentman_equipments';

    protected $fillable = [
        'company_id',
        'rentman_id',
        'name',
        'displayname',
        'code',
        'update_hash',
        'synced_at',
        'is_imported',
        'imported_at',
    ];

    protected $casts = [
        'is_imported' => 'boolean',
        'synced_at' => 'datetime',
        'imported_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Local keyword search: starts-with matches rank before contains-only matches.
     */
    public static function searchLocal(int $companyId, string $term): Collection
    {
        $trimmed = trim($term);
        if (strlen($trimmed) < 2) {
            return new Collection();
        }

        $escaped = self::escapeLike($trimmed);
        $starts = $escaped . '%';
        $contains = '%' . $escaped . '%';

        return static::query()
            ->where('company_id', $companyId)
            ->where(function ($w) use ($contains) {
                $w->where('name', 'LIKE', $contains)
                    ->orWhere('displayname', 'LIKE', $contains)
                    ->orWhere('code', 'LIKE', $contains);
            })
            ->orderByRaw(
                '(CASE WHEN COALESCE(name, \'\') LIKE ? OR COALESCE(displayname, \'\') LIKE ? OR COALESCE(code, \'\') LIKE ? THEN 0 ELSE 1 END)',
                [$starts, $starts, $starts]
            )
            ->orderByRaw('COALESCE(NULLIF(displayname, \'\'), name)')
            ->limit(20)
            ->get();
    }
}
