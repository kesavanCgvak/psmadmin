@extends('adminlte::page')

@section('title', 'Sub-Category Details')

@section('content_header')
    <h1>Sub-Category Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">{{ $subcategory->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $subcategory->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $subcategory->name }}</dd>

                        <dt class="col-sm-4">Category</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-primary">{{ $subcategory->category?->name ?? 'N/A' }}</span>
                        </dd>

                        <dt class="col-sm-4">Products</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-success">{{ $subcategory->products->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $subcategory->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $subcategory->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('subcategories.edit', $subcategory) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('subcategories.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Products in this Sub-Category</h3>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($subcategory->products->count() > 0)
                        <ul class="list-group">
                            @foreach($subcategory->products as $product)
                                <li class="list-group-item">
                                    <strong>{{ $product->brand?->name }}</strong> - {{ $product->model }}
                                    @if($product->psm_code)
                                        <br><small class="text-muted">PSM Code: {{ $product->psm_code }}</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No products in this sub-category yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

