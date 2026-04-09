@extends('adminlte::page')

@section('title', 'Edit CMS Page')

@section('content_header')
    <h1>Edit CMS Page</h1>
@stop

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <form action="{{ route('admin.cms-pages.update', $cmsPage) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="card-body">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $cmsPage->title) }}" required maxlength="255">
                    @error('title')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <label for="slug">URL slug</label>
                    <input type="text" class="form-control @error('slug') is-invalid @enderror" id="slug" name="slug" value="{{ old('slug', $cmsPage->slug) }}" required maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" title="Lowercase letters, numbers, and hyphens only">
                    @error('slug')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    <small class="form-text text-muted">Public URL: <span class="text-monospace">{{ url('/page/' . $cmsPage->slug) }}</span></small>
                </div>
                <div class="form-group">
                    <label for="content_html">Content</label>
                    <textarea class="form-control @error('content_html') is-invalid @enderror" id="content_html" name="content_html" rows="18">{{ old('content_html', $cmsPage->content_html) }}</textarea>
                    @error('content_html')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group">
                    <input type="hidden" name="is_published" value="0">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="is_published" name="is_published" value="1" @checked(old('is_published', $cmsPage->is_published ? '1' : '0') === '1')>
                        <label class="custom-control-label" for="is_published">Published (visible on public site)</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="meta_title">Meta title (optional)</label>
                    <input type="text" class="form-control @error('meta_title') is-invalid @enderror" id="meta_title" name="meta_title" value="{{ old('meta_title', $cmsPage->meta_title) }}" maxlength="255">
                    @error('meta_title')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div class="form-group mb-0">
                    <label for="meta_description">Meta description (optional)</label>
                    <textarea class="form-control @error('meta_description') is-invalid @enderror" id="meta_description" name="meta_description" rows="2" maxlength="512">{{ old('meta_description', $cmsPage->meta_description) }}</textarea>
                    @error('meta_description')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                <a href="{{ route('admin.cms-pages.index') }}" class="btn btn-default">Back to list</a>
            </div>
        </form>
    </div>
@stop

@section('js')
@include('admin.partials.tinymce-wysiwyg', ['selector' => '#content_html', 'height' => 550])
@stop
