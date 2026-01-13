@extends('adminlte::page')

@section('title', 'Pricing Scheme Details')

@section('content_header')
    <h1>Pricing Scheme Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">{{ $pricingScheme->code }} - {{ $pricingScheme->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $pricingScheme->id }}</dd>

                        <dt class="col-sm-4">Code</dt>
                        <dd class="col-sm-8"><span class="badge badge-primary">{{ $pricingScheme->code }}</span></dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8"><strong>{{ $pricingScheme->name }}</strong></dd>

                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8">{{ $pricingScheme->description ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">Companies Using</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $pricingScheme->companies->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $pricingScheme->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $pricingScheme->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.pricing-schemes.edit', $pricingScheme) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.pricing-schemes.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Companies Using This Pricing Scheme</h3>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($pricingScheme->companies->count() > 0)
                        <ul class="list-group">
                            @foreach($pricingScheme->companies as $company)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $company->name }}
                                    <span class="badge badge-primary badge-pill">{{ $company->country?->name ?? 'N/A' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No companies using this pricing scheme yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

