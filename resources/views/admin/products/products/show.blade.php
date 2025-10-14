@extends('adminlte::page')

@section('title', 'Product Details')

@section('content_header')
    <h1>Product Details</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">{{ $product->brand?->name }} {{ $product->model }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $product->id }}</dd>

                        <dt class="col-sm-4">Brand</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-success">{{ $product->brand?->name ?? 'N/A' }}</span>
                        </dd>

                        <dt class="col-sm-4">Model</dt>
                        <dd class="col-sm-8"><strong>{{ $product->model }}</strong></dd>

                        <dt class="col-sm-4">Category</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-primary">{{ $product->category?->name ?? 'N/A' }}</span>
                        </dd>

                        <dt class="col-sm-4">Sub-Category</dt>
                        <dd class="col-sm-8">
                            @if($product->subCategory)
                                <span class="badge badge-info">{{ $product->subCategory->name }}</span>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">PSM Code</dt>
                        <dd class="col-sm-8">
                            @if($product->psm_code)
                                <code>{{ $product->psm_code }}</code>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Equipment Count</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-danger">{{ $product->equipments->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $product->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $product->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('products.edit', $product) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('products.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Product Summary</h3>
                </div>
                <div class="card-body">
                    <div class="callout callout-info">
                        <h5>Full Product Name</h5>
                        <p>{{ $product->brand?->name }} {{ $product->model }}</p>
                    </div>

                    <div class="callout callout-warning">
                        <h5>Classification</h5>
                        <p>
                            <strong>Category:</strong> {{ $product->category?->name ?? 'N/A' }}<br>
                            @if($product->subCategory)
                                <strong>Sub-Category:</strong> {{ $product->subCategory->name }}<br>
                            @endif
                            <strong>Brand:</strong> {{ $product->brand?->name ?? 'N/A' }}
                        </p>
                    </div>

                    @if($product->psm_code)
                        <div class="callout callout-success">
                            <h5>PSM Code</h5>
                            <p><code style="font-size: 1.1em;">{{ $product->psm_code }}</code></p>
                        </div>
                    @endif
                </div>
            </div>

            @if($product->equipments->count() > 0)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Equipment Using This Product</h3>
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <ul class="list-group">
                            @foreach($product->equipments as $equipment)
                                <li class="list-group-item">
                                    <strong>Qty: {{ $equipment->quantity }}</strong> -
                                    Price: ${{ number_format($equipment->price, 2) }}
                                    @if($equipment->company)
                                        <br><small class="text-muted">Company: {{ $equipment->company->name }}</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </div>
@stop

