@extends('adminlte::page')

@section('title', 'Rental Software Details')

@section('content_header')
    <h1>Rental Software Details</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">{{ $rentalSoftware->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $rentalSoftware->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8"><strong>{{ $rentalSoftware->name }}</strong></dd>

                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8">{{ $rentalSoftware->description ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">Version</dt>
                        <dd class="col-sm-8">
                            @if($rentalSoftware->version)
                                <span class="badge badge-secondary">v{{ $rentalSoftware->version }}</span>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Price</dt>
                        <dd class="col-sm-8">
                            @if($rentalSoftware->price)
                                <strong class="text-success">${{ number_format($rentalSoftware->price, 2) }}</strong> /month
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Companies Using</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $rentalSoftware->companies->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $rentalSoftware->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $rentalSoftware->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.rental-software.edit', $rentalSoftware) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.rental-software.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Companies Using This Software</h3>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($rentalSoftware->companies->count() > 0)
                        <ul class="list-group">
                            @foreach($rentalSoftware->companies as $company)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $company->name }}
                                    <div>
                                        @if($company->country)
                                            <span class="badge badge-success">{{ $company->country->name }}</span>
                                        @endif
                                        <span class="badge badge-primary badge-pill">{{ $company->users->count() }} users</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No companies using this software yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

