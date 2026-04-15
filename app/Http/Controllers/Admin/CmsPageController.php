<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Stevebauman\Purify\Facades\Purify;

class CmsPageController extends Controller
{
    public function index()
    {
        $pages = CmsPage::query()
            ->orderBy('title')
            ->paginate(config('app.admin_list_per_page'))
            ->withQueryString();

        return view('admin.cms-pages.index', compact('pages'));
    }

    public function create()
    {
        return view('admin.cms-pages.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:cms_pages,slug',
            'content_html' => 'required|string',
            'is_published' => 'sometimes|boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:512',
        ]);

        CmsPage::create([
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'content_html' => Purify::config('wysiwyg')->clean($validated['content_html']),
            'is_published' => $request->boolean('is_published', true),
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ]);

        return redirect()
            ->route('admin.cms-pages.index')
            ->with('success', 'Page created successfully.');
    }

    public function edit(CmsPage $cms_page)
    {
        return view('admin.cms-pages.edit', ['cmsPage' => $cms_page]);
    }

    public function update(Request $request, CmsPage $cms_page)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:cms_pages,slug,'.$cms_page->id,
            'content_html' => 'required|string',
            'is_published' => 'sometimes|boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:512',
        ]);

        $cms_page->update([
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'content_html' => Purify::config('wysiwyg')->clean($validated['content_html']),
            'is_published' => $request->boolean('is_published', true),
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
        ]);

        return redirect()
            ->route('admin.cms-pages.index')
            ->with('success', 'Page updated successfully.');
    }

    public function destroy(CmsPage $cms_page)
    {
        $cms_page->delete();

        return redirect()
            ->route('admin.cms-pages.index')
            ->with('success', 'Page deleted successfully.');
    }

    /**
     * Upload image from TinyMCE and return a permanent URL.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
        ]);

        $file = $request->file('file');
        $directory = public_path('uploads/cms-pages');
        File::ensureDirectoryExists($directory);

        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $file->move($directory, $filename);

        return response()->json([
            'location' => asset('uploads/cms-pages/'.$filename),
        ]);
    }
}
