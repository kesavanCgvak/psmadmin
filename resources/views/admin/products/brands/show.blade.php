@extends('adminlte::page')

@section('title', 'Brand Details')

@section('content_header')
    <h1>Brand Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">{{ $brand->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $brand->id }}</dd>

                        <dt class="col-sm-4">Brand Name</dt>
                        <dd class="col-sm-8">{{ $brand->name }}</dd>

                        <dt class="col-sm-4">Products</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-success">{{ $brand->products->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $brand->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $brand->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.brands.edit', $brand) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.brands.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Products by this Brand</h3>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($brand->products->count() > 0)
                        <ul class="list-group">
                            @foreach($brand->products as $product)
                                <li class="list-group-item">
                                    <a href="{{ route('admin.products.show', ['product' => $product, 'from_brand' => $brand->id]) }}" class="text-body">
                                        <strong>{{ $product->model }}</strong>
                                    </a>
                                    <br>
                                    <small class="text-muted">
                                        Category: {{ $product->category?->name ?? 'N/A' }}
                                        @if($product->subCategory)
                                            / {{ $product->subCategory->name }}
                                        @endif
                                    </small>
                                    @if($product->psm_code)
                                        <br><small class="text-info">PSM: {{ $product->psm_code }}</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No products for this brand yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

