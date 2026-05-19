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
            <div class="mb-3 d-flex flex-wrap align-items-center">
                <span class="text-muted small mr-2 mb-2">Filter:</span>
                <div class="btn-group btn-group-sm flex-wrap mb-2">
                    <a href="{{ route('admin.cms-pages.index') }}" class="btn btn-default @if(empty($sectionFilter)) active @endif">All</a>
                    @foreach($sections as $value => $label)
                        <a href="{{ route('admin.cms-pages.index', ['section' => $value]) }}" class="btn btn-default @if($sectionFilter === $value) active @endif">{{ $label }}</a>
                    @endforeach
                </div>
            </{{ 'div' }}>
            @if($pages->isEmpty())
                <p class="text-muted mb-0">No pages yet. Create one and set <strong>Menu section</strong> to <strong>About Us menu</strong> for the frontend About Us dropdown.</p>
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
                                        <span class="badge badge-info">{{ $sections[$page->section] ?? $page->section }}</span>
                                        @if($page->sort_order > 0)
                                            <span class="badge badge-light text-dark">Order {{ $page->sort_order }}</span>
                                        @endif
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
