@extends('layouts.simple-content')

@section('title', ($page->meta_title ?: $page->title) . ' — ' . config('app.name'))

@push('meta')
@if($page->meta_description)
<meta name="description" content="{{ $page->meta_description }}">
@endif
@endpush

@section('content')
<article class="cms-page">
    <header class="cms-page-header">
        <h1 class="cms-page-title">{{ $page->title }}</h1>
        <p class="cms-page-updated">Updated {{ $page->updated_at->format(config('app.datetime_format')) }}</p>
    </header>
    <div class="cms-page-body">
        {!! $page->content_html !!}
    </div>
</article>
@endsection
