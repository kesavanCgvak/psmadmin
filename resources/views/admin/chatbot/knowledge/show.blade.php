@extends('adminlte::page')

@section('title', 'Knowledge Entry Details')

@section('content_header')
    <h1>Knowledge Entry Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
    <link rel="stylesheet" href="{{ asset('common/css/chatbot.css') }}">
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $knowledge->title }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">ID</dt>
                        <dd class="col-sm-9">{{ $knowledge->id }}</dd>

                        <dt class="col-sm-3">Title</dt>
                        <dd class="col-sm-9">{{ $knowledge->title }}</dd>

                        <dt class="col-sm-3">Category</dt>
                        <dd class="col-sm-9">
                            @if($knowledge->category)
                                <span class="badge badge-info">{{ $knowledge->category }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Question / topic</dt>
                        <dd class="col-sm-9">{{ $knowledge->question ?: '—' }}</dd>

                        <dt class="col-sm-3">Answer / content</dt>
                        <dd class="col-sm-9 chatbot-knowledge-content">{{ $knowledge->content }}</dd>

                        <dt class="col-sm-3">Keywords</dt>
                        <dd class="col-sm-9">{{ $knowledge->keywords ?: '—' }}</dd>

                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            @if($knowledge->is_active)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Inactive</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Sort order</dt>
                        <dd class="col-sm-9">{{ $knowledge->sort_order }}</dd>

                        <dt class="col-sm-3">Created At</dt>
                        <dd class="col-sm-9">{{ $knowledge->created_at?->format(config('app.datetime_format')) }}</dd>

                        <dt class="col-sm-3">Updated At</dt>
                        <dd class="col-sm-9">{{ $knowledge->updated_at?->format(config('app.datetime_format')) }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.chatbot.knowledge.edit', $knowledge) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.chatbot.knowledge.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
@stop
