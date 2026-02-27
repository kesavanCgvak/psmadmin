@extends('adminlte::page')

@section('title', 'Date Format Details')

@section('content_header')
    <h1>Date Format Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">{{ $dateFormat->name }}</h3>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <h1 style="font-size: 3em;">{{ $dateFormat->format }}</h1>
                    </div>

                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $dateFormat->id }}</dd>

                        <dt class="col-sm-4">Format</dt>
                        <dd class="col-sm-8"><span class="badge badge-primary">{{ $dateFormat->format }}</span></dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8"><strong>{{ $dateFormat->name }}</strong></dd>

                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8">{{ $dateFormat->description ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">Companies Using</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $dateFormat->companies->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $dateFormat->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $dateFormat->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.date-formats.edit', $dateFormat) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.date-formats.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Companies Using This Date Format</h3>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($dateFormat->companies->count() > 0)
                        <ul class="list-group">
                            @foreach($dateFormat->companies as $company)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $company->name }}
                                    <span class="badge badge-primary badge-pill">{{ $company->country?->name ?? 'N/A' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No companies using this date format yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

