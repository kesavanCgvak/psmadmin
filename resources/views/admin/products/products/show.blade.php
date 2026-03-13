@extends('adminlte::page')

@section('title', 'Product Details')

@section('content_header')
    <h1>Product Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
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
                        <dt class="col-sm-4">Webpage URL</dt>
                        <dd class="col-sm-8">
                            <!-- <span class="badge badge-primary">{{ $product->webpage_url ?? 'N/A' }}</span>? -->
                            @if($product->webpage_url)
                            <a href="{{ $product->webpage_url }}" target="_blank" class="btn btn-primary btn-sm">
                                <i class="fas fa-external-link-alt"></i> View
                            </a>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">Equipment Count</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-danger">{{ $product->equipments->count() }}</span>
                        </dd>

                        @if($product->height !== null || $product->width !== null || $product->length !== null)
                            <dt class="col-sm-4">Dimensions</dt>
                            <dd class="col-sm-8">
                                {{ $product->height ?? '-' }} × {{ $product->width ?? '-' }} × {{ $product->length ?? '-' }}
                                @if($product->linearUnit)
                                    <span class="badge badge-success">{{ $product->linearUnit->code }}</span>
                                @endif
                            </dd>
                        @endif

                        @if($product->weight !== null)
                            <dt class="col-sm-4">Weight</dt>
                            <dd class="col-sm-8">
                                {{ $product->weight }}
                                @if($product->weightUnit)
                                    <span class="badge badge-success">{{ $product->weightUnit->code }}</span>
                                @endif
                            </dd>
                        @endif

                        <dt class="col-sm-4">Replacement Price</dt>
                        <dd class="col-sm-8">{{ $product->replacement_price !== null ? number_format($product->replacement_price, 2) : '—' }}</dd>

                        @if($product->country_of_origin || $product->iso_code_2 || $product->iso_code_3 || $product->hsn_code)
                            <dt class="col-sm-4">Country of Origin</dt>
                            <dd class="col-sm-8">{{ $product->country_of_origin ?? '-' }}</dd>

                            <dt class="col-sm-4">ISO Code 2</dt>
                            <dd class="col-sm-8">{{ $product->iso_code_2 ?? '-' }}</dd>

                            <dt class="col-sm-4">ISO Code 3</dt>
                            <dd class="col-sm-8">{{ $product->iso_code_3 ?? '-' }}</dd>

                            <dt class="col-sm-4">HSN Code</dt>
                            <dd class="col-sm-8">{{ $product->hsn_code ?? '-' }}</dd>
                        @endif

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $product->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $product->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <div>
                        <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-warning">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="{{ route('admin.products.index') }}" class="btn btn-default">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                    @if(request()->has('from_brand'))
                        <a href="{{ route('admin.brands.show', request()->query('from_brand')) }}" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Brand
                        </a>
                    @endif
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

                    @if($product->height !== null || $product->width !== null || $product->length !== null || $product->weight !== null)
                        <div class="callout callout-info">
                            <h5>Dimensions & Weight</h5>
                            <p>
                                @if($product->height !== null || $product->width !== null || $product->length !== null)
                                    <strong>Dimensions:</strong> {{ $product->height ?? '-' }} × {{ $product->width ?? '-' }} × {{ $product->length ?? '-' }}
                                    @if($product->linearUnit)
                                        {{ $product->linearUnit->code }}
                                    @endif
                                    <br>
                                @endif
                                @if($product->weight !== null)
                                    <strong>Weight:</strong> {{ $product->weight }}
                                    @if($product->weightUnit)
                                        {{ $product->weightUnit->code }}
                                    @endif
                                @endif
                            </p>
                        </div>
                    @endif

                    @if($product->country_of_origin || $product->iso_code_2 || $product->iso_code_3 || $product->hsn_code)
                        <div class="callout callout-primary">
                            <h5>Additional Information</h5>
                            <p>
                                <strong>Country of Origin:</strong> {{ $product->country_of_origin ?? '-' }}<br>
                                <strong>ISO Code 2:</strong> {{ $product->iso_code_2 ?? '-' }}<br>
                                <strong>ISO Code 3:</strong> {{ $product->iso_code_3 ?? '-' }}<br>
                                <strong>HSN Code:</strong> {{ $product->hsn_code ?? '-' }}
                            </p>
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
                                    Rental Price: ${{ number_format($equipment->rental_price, 2) }}
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

