<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CmsPageController extends Controller
{
    /**
     * Public API: list published CMS pages for menus / navigation.
     * Optional ?section=about_us (or general) to filter by admin "menu section".
     * Must stay registered before GET /cms-pages/{slug} so "cms-pages" is not captured as a slug.
     */
    public function index(Request $request): JsonResponse
    {
        $section = $this->resolveSectionFilter($request->query('section'));

        if ($section === false) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid section',
                'data' => null,
            ], 422);
        }

        $query = CmsPage::query()->forMenu();

        if ($section !== null) {
            $query->inSection($section);
        }

        $pages = $query
            ->get(['slug', 'title', 'section', 'sort_order'])
            ->map(fn (CmsPage $page) => $this->menuItemPayload($page))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'CMS pages retrieved successfully',
            'data' => $pages,
        ]);
    }

    /**
     * Public API: list published pages for the frontend "About Us" menu.
     * Registered before GET /cms-pages/{slug}.
     */
    public function aboutUsIndex(): JsonResponse
    {
        $pages = CmsPage::query()
            ->forMenu()
            ->inSection(CmsPage::SECTION_ABOUT_US)
            ->get(['slug', 'title', 'section', 'sort_order'])
            ->map(fn (CmsPage $page) => $this->menuItemPayload($page))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'About Us pages retrieved successfully',
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
                'section' => $page->section,
                'sort_order' => $page->sort_order,
                'content_html' => $page->content_html,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'updated_at' => $page->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * @return array{slug: string, title: string, section: string, sort_order: int}
     */
    private function menuItemPayload(CmsPage $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'section' => $page->section,
            'sort_order' => $page->sort_order,
        ];
    }

    /**
     * @return string|null|false null = no filter, string = valid section, false = invalid
     */
    private function resolveSectionFilter(mixed $section): string|null|false
    {
        if ($section === null || $section === '') {
            return null;
        }

        if (! is_string($section)) {
            return false;
        }

        $allowed = array_keys(CmsPage::sectionOptions());

        return in_array($section, $allowed, true) ? $section : false;
    }
}
