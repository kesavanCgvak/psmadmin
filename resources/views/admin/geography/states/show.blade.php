@extends('adminlte::page')

@section('title', 'State/Province Details')

@section('content_header')
    <h1>State/Province Details</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">{{ $state->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $state->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $state->name }}</dd>

                        <dt class="col-sm-4">Country</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-success">{{ $state->country?->name ?? 'N/A' }}</span>
                        </dd>

                        <dt class="col-sm-4">Code</dt>
                        <dd class="col-sm-8">{{ $state->code ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">Type</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-primary">{{ ucfirst(str_replace('_', ' ', $state->type)) }}</span>
                        </dd>

                        <dt class="col-sm-4">Cities Count</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-warning">{{ $state->cities->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $state->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $state->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('states.edit', $state) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('states.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Cities in this State/Province</h3>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($state->cities->count() > 0)
                        <ul class="list-group">
                            @foreach($state->cities as $city)
                                <li class="list-group-item">
                                    {{ $city->name }}
                                    @if($city->latitude && $city->longitude)
                                        <small class="text-muted">({{ number_format($city->latitude, 4) }}, {{ number_format($city->longitude, 4) }})</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No cities added yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

