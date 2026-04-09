<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use Illuminate\View\View;

class CmsPageController extends Controller
{
    /**
     * Public site: render a CMS page by slug (WordPress-like).
     */
    public function show(CmsPage $cmsPage): View
    {
        if (! $cmsPage->is_published) {
            abort(404);
        }

        return view('cms.page', [
            'page' => $cmsPage,
        ]);
    }
}
