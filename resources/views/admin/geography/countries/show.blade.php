@extends('adminlte::page')

@section('title', 'Country Details')

@section('content_header')
    <h1>Country Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">{{ $country->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $country->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $country->name }}</dd>

                        <dt class="col-sm-4">Region</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-primary">{{ $country->region?->name ?? 'N/A' }}</span>
                        </dd>

                        <dt class="col-sm-4">ISO Code</dt>
                        <dd class="col-sm-8"><span class="badge badge-secondary">{{ $country->iso_code }}</span></dd>

                        <dt class="col-sm-4">Phone Code</dt>
                        <dd class="col-sm-8">{{ $country->phone_code ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">States/Provinces</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $country->statesProvinces->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Cities</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-warning">{{ $country->cities->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $country->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $country->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('countries.edit', $country) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('countries.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">States/Provinces</h3>
                </div>
                <div class="card-body">
                    @if($country->statesProvinces->count() > 0)
                        <ul class="list-group">
                            @foreach($country->statesProvinces as $state)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $state->name }}
                                    <span class="badge badge-primary badge-pill">{{ ucfirst($state->type) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No states/provinces added yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

