<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CmsPage extends Model
{
    public const SECTION_GENERAL = 'general';

    public const SECTION_ABOUT_US = 'about_us';

    protected $fillable = [
        'slug',
        'section',
        'sort_order',
        'title',
        'content_html',
        'is_published',
        'meta_title',
        'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sectionOptions(): array
    {
        return config('cms.sections', [
            self::SECTION_GENERAL => 'Tech support',
            self::SECTION_ABOUT_US => 'About Us',
        ]);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeInSection(Builder $query, string $section): Builder
    {
        return $query->where('section', $section);
    }

    public function scopeForMenu(Builder $query): Builder
    {
        return $query->published()->orderBy('sort_order')->orderBy('title');
    }

    /**
     * Use slug for implicit route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
