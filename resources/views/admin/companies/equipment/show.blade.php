@extends('adminlte::page')

@section('title', 'Equipment Details')

@section('content_header')
    <h1>Equipment Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">{{ $equipment->product->brand?->name }} {{ $equipment->product->model }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">ID</dt>
                        <dd class="col-sm-9">{{ $equipment->id }}</dd>

                        <dt class="col-sm-3">Company</dt>
                        <dd class="col-sm-9">
                            <span class="badge badge-primary">{{ $equipment->company?->name ?? 'N/A' }}</span>
                        </dd>

                        <dt class="col-sm-3">Owner/User</dt>
                        <dd class="col-sm-9">
                            <strong>{{ $equipment->user->username }}</strong>
                            <br>
                            <small class="text-muted">{{ $equipment->user->profile?->email ?? 'No email' }}</small>
                        </dd>

                        <dt class="col-sm-3">Product</dt>
                        <dd class="col-sm-9">
                            <strong>{{ $equipment->product->brand?->name }} {{ $equipment->product->model }}</strong>
                        </dd>

                        <dt class="col-sm-3">Category</dt>
                        <dd class="col-sm-9">
                            <span class="badge badge-primary">{{ $equipment->product->category?->name ?? 'N/A' }}</span>
                            @if($equipment->product->subCategory)
                                → <span class="badge badge-info">{{ $equipment->product->subCategory->name }}</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">PSM Code</dt>
                        <dd class="col-sm-9">
                            @if($equipment->product->psm_code)
                                <code>{{ $equipment->product->psm_code }}</code>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Quantity</dt>
                        <dd class="col-sm-9">
                            <span class="badge badge-warning" style="font-size: 1.2em;">{{ $equipment->quantity }}</span>
                        </dd>

                        <dt class="col-sm-3">Price</dt>
                        <dd class="col-sm-9">
                            <strong class="text-success" style="font-size: 1.2em;">${{ number_format($equipment->price, 2) }}</strong> /day
                        </dd>

                        <dt class="col-sm-3">Software Code</dt>
                        <dd class="col-sm-9">
                            @if($equipment->software_code)
                                <code>{{ $equipment->software_code }}</code>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9">{{ $equipment->description ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Created At</dt>
                        <dd class="col-sm-9">{{ $equipment->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-3">Updated At</dt>
                        <dd class="col-sm-9">{{ $equipment->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.equipment.edit', $equipment) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.equipment.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Equipment Images -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Equipment Images</h3>
                </div>
                <div class="card-body">
                    @if($equipment->images->count() > 0)
                        <div class="row">
                            @foreach($equipment->images as $image)
                                <div class="col-md-6 mb-3">
                                    <img src="{{ asset($image->image_path) }}"
                                         class="img-fluid img-thumbnail"
                                         alt="Equipment Image">
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted">No images uploaded yet.</p>
                    @endif
                </div>
            </div>

            <!-- Calculation -->
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">Pricing Calculator</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-6">Price per Day</dt>
                        <dd class="col-sm-6">${{ number_format($equipment->price, 2) }}</dd>

                        <dt class="col-sm-6">Week (7 days)</dt>
                        <dd class="col-sm-6">${{ number_format($equipment->price * 7, 2) }}</dd>

                        <dt class="col-sm-6">Month (30 days)</dt>
                        <dd class="col-sm-6">${{ number_format($equipment->price * 30, 2) }}</dd>

                        <dt class="col-sm-6">Quantity Available</dt>
                        <dd class="col-sm-6"><span class="badge badge-warning">{{ $equipment->quantity }}</span></dd>

                        <dt class="col-sm-6">Total Value</dt>
                        <dd class="col-sm-6">
                            <strong class="text-success">${{ number_format($equipment->price * $equipment->quantity, 2) }}</strong>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@stop

