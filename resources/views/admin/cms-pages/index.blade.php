@extends('adminlte::page')

@section('title', 'CMS Pages')

@section('content_header')
    <h1>CMS Pages</h1>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-check"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-ban"></i> {{ session('error') }}
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
            <h3 class="card-title mb-2 mb-sm-0">All pages</h3>
            <a href="{{ route('admin.cms-pages.create') }}" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> New page
            </a>
        </div>
        <div class="card-body">
            @if($pages->isEmpty())
                <p class="text-muted mb-0">No pages yet. Create one to show content on the public site (e.g. About).</p>
            @else
                <div class="cms-page-list">
                    @foreach($pages as $page)
                        <div class="card card-outline card-secondary mb-3">
                            <div class="card-body py-3">
                                <div class="row align-items-center">
                                    <div class="col-12 col-md-6 mb-2 mb-md-0">
                                        <div class="font-weight-bold">{{ $page->title }}</div>
                                        <div class="text-muted small">
                                            <span class="d-block d-sm-inline">Slug: <code>{{ $page->slug }}</code></span>
                                            <span class="d-none d-sm-inline"> · </span>
                                            <span class="d-block d-sm-inline">Updated {{ $page->updated_at->format(config('app.datetime_format')) }}</span>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-3 mb-2 mb-md-0">
                                        @if($page->is_published)
                                            <span class="badge badge-success">Published</span>
                                        @else
                                            <span class="badge badge-secondary">Draft</span>
                                        @endif
                                    </div>
                                    <div class="col-12 col-md-3 text-md-right">
                                        <div class="btn-group flex-wrap">
                                            @if($page->is_published)
                                                <a href="{{ route('cms.page.show', $page) }}" class="btn btn-default btn-sm" target="_blank" rel="noopener noreferrer" title="View public page">
                                                    <i class="fas fa-external-link-alt"></i> View
                                                </a>
                                            @endif
                                            <a href="{{ route('admin.cms-pages.edit', $page) }}" class="btn btn-warning btn-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form action="{{ route('admin.cms-pages.destroy', $page) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this page?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="d-flex justify-content-center mt-3">
                    {{ $pages->links() }}
                </div>
            @endif
        </div>
    </div>
@stop
