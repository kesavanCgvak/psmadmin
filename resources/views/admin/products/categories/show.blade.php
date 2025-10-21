@extends('adminlte::page')

@section('title', 'Category Details')

@section('content_header')
    <h1>Category Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $category->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $category->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $category->name }}</dd>

                        <dt class="col-sm-4">Sub-Categories</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-info">{{ $category->subCategories->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Products</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-success">{{ $category->products->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $category->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $category->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.categories.edit', $category) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.categories.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Sub-Categories</h3>
                </div>
                <div class="card-body">
                    @if($category->subCategories->count() > 0)
                        <ul class="list-group">
                            @foreach($category->subCategories as $subCategory)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    {{ $subCategory->name }}
                                    <span class="badge badge-primary badge-pill">{{ $subCategory->products->count() }} products</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted">No sub-categories added yet.</p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Recent Products</h3>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    @if($category->products->count() > 0)
                        <ul class="list-group">
                            @foreach($category->products->take(10) as $product)
                                <li class="list-group-item">
                                    <strong>{{ $product->brand?->name }}</strong> - {{ $product->model }}
                                    @if($product->psm_code)
                                        <small class="text-muted">({{ $product->psm_code }})</small>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if($category->products->count() > 10)
                            <p class="text-muted mt-2">... and {{ $category->products->count() - 10 }} more products</p>
                        @endif
                    @else
                        <p class="text-muted">No products in this category yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

