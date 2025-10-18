@extends('adminlte::page')

@section('title', 'Region Details')

@section('content_header')
    <h1>Region Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $region->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $region->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $region->name }}</dd>

                        <dt class="col-sm-4">Countries Count</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $region->countries->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $region->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $region->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('regions.edit', $region) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('regions.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Countries in this Region</h3>
                </div>
                <div class="card-body">
                    @if($region->countries->count() > 0)
                        <ul class="list-group">
                            @foreach($region->countries as $country)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $country->name }}
                                    <span class="badge badge-primary badge-pill">{{ $country->iso_code }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No countries in this region yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

