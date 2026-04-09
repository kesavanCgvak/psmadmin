<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;

class CmsPageController extends Controller
{
    /**
     * Public API: list published CMS pages (slug + title) for menus / navigation.
     * Must stay registered before GET /cms-pages/{slug} so "cms-pages" is not captured as a slug.
     */
    public function index(): JsonResponse
    {
        $pages = CmsPage::query()
            ->where('is_published', true)
            ->orderBy('title')
            ->get(['slug', 'title'])
            ->map(fn (CmsPage $page) => [
                'slug' => $page->slug,
                'title' => $page->title,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'CMS pages retrieved successfully',
            'data' => $pages,
        ]);
    }

    /**
     * Public API: fetch a published CMS page by slug (for mobile / SPA frontends).
     */
    public function show(string $slug): JsonResponse
    {
        $page = CmsPage::query()->where('slug', $slug)->first();

        if (! $page || ! $page->is_published) {
            return response()->json([
                'success' => false,
                'message' => 'Page not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Page retrieved successfully',
            'data' => [
                'slug' => $page->slug,
                'title' => $page->title,
                'content_html' => $page->content_html,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'updated_at' => $page->updated_at->toISOString(),
            ],
        ]);
    }
}
