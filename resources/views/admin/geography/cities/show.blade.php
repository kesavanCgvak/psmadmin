@extends('adminlte::page')

@section('title', 'City Details')

@section('content_header')
    <h1>City Details</h1>
@stop

@section('content')
    <div class="card card-warning">
        <div class="card-header">
            <h3 class="card-title">{{ $city->name }}</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $city->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $city->name }}</dd>

                        <dt class="col-sm-4">Country</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-success">{{ $city->country?->name ?? 'N/A' }}</span>
                        </dd>

                        <dt class="col-sm-4">State/Province</dt>
                        <dd class="col-sm-8">
                            @if($city->state)
                                <span class="badge badge-info">{{ $city->state->name }}</span>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Latitude</dt>
                        <dd class="col-sm-8">{{ $city->latitude ? number_format($city->latitude, 7) : 'N/A' }}</dd>

                        <dt class="col-sm-4">Longitude</dt>
                        <dd class="col-sm-8">{{ $city->longitude ? number_format($city->longitude, 7) : 'N/A' }}</dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $city->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $city->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>

                <div class="col-md-6">
                    @if($city->latitude && $city->longitude)
                        <div class="form-group">
                            <label>Location Map</label>
                            <div class="embed-responsive embed-responsive-16by9">
                                <iframe class="embed-responsive-item"
                                        src="https://maps.google.com/maps?q={{ $city->latitude }},{{ $city->longitude }}&z=12&output=embed"
                                        frameborder="0"
                                        scrolling="no"
                                        marginheight="0"
                                        marginwidth="0">
                                </iframe>
                            </div>
                        </div>
                    @else
                        <div class="alert alert-info">
                            <i class="icon fas fa-info-circle"></i>
                            No GPS coordinates available for this city.
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="card-footer">
            <a href="{{ route('cities.edit', $city) }}" class="btn btn-warning">
                <i class="fas fa-edit"></i> Edit
            </a>
            <a href="{{ route('cities.index') }}" class="btn btn-default">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>
@stop

