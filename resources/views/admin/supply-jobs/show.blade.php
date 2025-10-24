@extends('adminlte::page')

@section('title', 'Supply Job Details')

@section('content_header')
    <h1>Supply Job Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <!-- Job Information -->
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">Supply Job #{{ $supplyJob->id }}</h3>
                    <div class="card-tools">
                        @php
                            $statusColors = [
                                'pending' => 'warning',
                                'negotiating' => 'info',
                                'accepted' => 'success',
                                'cancelled' => 'danger',
                            ];
                            $statusColor = $statusColors[$supplyJob->status] ?? 'secondary';
                        @endphp
                        <span class="badge badge-{{ $statusColor }}">{{ ucfirst($supplyJob->status ?? 'N/A') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">Supply Job ID</dt>
                        <dd class="col-sm-9">{{ $supplyJob->id }}</dd>

                        <dt class="col-sm-3">Related Rental Job</dt>
                        <dd class="col-sm-9">
                            @if($supplyJob->rentalJob)
                                <a href="{{ route('admin.rental-jobs.show', $supplyJob->rentalJob) }}" class="btn btn-sm btn-primary">
                                    <i class="fas fa-briefcase"></i> {{ $supplyJob->rentalJob->name }}
                                </a>
                                <!-- <br><small class="text-muted">Job #{{ $supplyJob->rentalJob->id }}</small> -->
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Provider Company</dt>
                        <dd class="col-sm-9">
                            @if($supplyJob->provider)
                                <div>
                                    <a href="{{ route('admin.companies.show', $supplyJob->provider) }}" class="badge badge-info">
                                        {{ $supplyJob->provider->name }}
                                    </a>
                                    @if($supplyJob->provider->region)
                                        <br><small><i class="fas fa-map-marker-alt text-primary"></i> {{ $supplyJob->provider->region->name }}</small>
                                    @endif
                                    @if($supplyJob->provider->country)
                                        <small>, {{ $supplyJob->provider->country->name }}</small>
                                    @endif
                                    @if($supplyJob->provider->city)
                                        <small>, {{ $supplyJob->provider->city->name }}</small>
                                    @endif
                                    @if($supplyJob->provider->currency)
                                        <br><small><i class="fas fa-dollar-sign text-success"></i> Currency: {{ $supplyJob->provider->currency->code }}</small>
                                    @endif
                                </div>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Client</dt>
                        <dd class="col-sm-9">
                            @if($supplyJob->rentalJob && $supplyJob->rentalJob->user)
                                <div>
                                    <strong>{{ $supplyJob->rentalJob->user->username }}</strong>
                                    @if($supplyJob->rentalJob->user->profile)
                                        <br><small class="text-muted">{{ $supplyJob->rentalJob->user->profile->email ?? 'No email' }}</small>
                                        @if($supplyJob->rentalJob->user->profile->phone)
                                            <br><small class="text-muted"><i class="fas fa-phone"></i> {{ $supplyJob->rentalJob->user->profile->phone }}</small>
                                        @endif
                                    @endif
                                    @if($supplyJob->rentalJob->user->company)
                                        <br><a href="{{ route('admin.companies.show', $supplyJob->rentalJob->user->company) }}" class="badge badge-secondary">
                                            {{ $supplyJob->rentalJob->user->company->name }}
                                        </a>
                                    @endif
                                </div>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Quote Price</dt>
                        <dd class="col-sm-9">
                            @if($supplyJob->quote_price)
                                <strong class="text-success" style="font-size: 1.2em;">${{ number_format($supplyJob->quote_price, 2) }}</strong>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            <span class="badge badge-{{ $statusColor }}">{{ ucfirst($supplyJob->status ?? 'N/A') }}</span>
                        </dd>

                        <dt class="col-sm-3">Notes</dt>
                        <dd class="col-sm-9">{{ $supplyJob->notes ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Packing Date</dt>
                        <dd class="col-sm-9">
                            @if($supplyJob->packing_date)
                                <i class="fas fa-box text-primary"></i> {{ \Carbon\Carbon::parse($supplyJob->packing_date)->format('M d, Y') }}
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Delivery Date</dt>
                        <dd class="col-sm-9">
                            @if($supplyJob->delivery_date)
                                <i class="fas fa-truck text-success"></i> {{ \Carbon\Carbon::parse($supplyJob->delivery_date)->format('M d, Y') }}
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Return Date</dt>
                        <dd class="col-sm-9">
                            @if($supplyJob->return_date)
                                <i class="fas fa-undo text-warning"></i> {{ \Carbon\Carbon::parse($supplyJob->return_date)->format('M d, Y') }}
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Unpacking Date</dt>
                        <dd class="col-sm-9">
                            @if($supplyJob->unpacking_date)
                                <i class="fas fa-box-open text-info"></i> {{ \Carbon\Carbon::parse($supplyJob->unpacking_date)->format('M d, Y') }}
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Created At</dt>
                        <dd class="col-sm-9">{{ $supplyJob->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-3">Updated At</dt>
                        <dd class="col-sm-9">{{ $supplyJob->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    @if($supplyJob->rentalJob)
                        <a href="{{ route('admin.rental-jobs.show', $supplyJob->rentalJob) }}" class="btn btn-primary">
                            <i class="fas fa-briefcase"></i> View Rental Job
                        </a>
                    @endif
                    <a href="{{ route('admin.supply-jobs.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Statistics -->
            <div class="card card-widget widget-user-2">
                <div class="widget-user-header bg-success">
                    <h3 class="widget-user-username">Job Statistics</h3>
                </div>
                <div class="card-footer p-0">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <span class="nav-link">
                                Products <span class="float-right badge badge-primary">{{ $supplyJob->products->count() }}</span>
                            </span>
                        </li>
                        <li class="nav-item">
                            <span class="nav-link">
                                Comments <span class="float-right badge badge-info">{{ $supplyJob->comments->count() }}</span>
                            </span>
                        </li>
                        @if($supplyJob->quote_price && $supplyJob->products->count() > 0)
                            <li class="nav-item">
                                <span class="nav-link">
                                    Avg Price/Product <span class="float-right badge badge-success">${{ number_format($supplyJob->quote_price / $supplyJob->products->count(), 2) }}</span>
                                </span>
                            </li>
                        @endif
                    </ul>
                </div>
            </div>

            <!-- Timeline -->
            @if($supplyJob->packing_date || $supplyJob->delivery_date || $supplyJob->return_date || $supplyJob->unpacking_date)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calendar-alt"></i> Timeline</h3>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            @if($supplyJob->packing_date)
                                <li class="list-group-item">
                                    <i class="fas fa-box text-primary"></i> <strong>Packing</strong>
                                    <span class="float-right text-muted">{{ \Carbon\Carbon::parse($supplyJob->packing_date)->format('M d, Y') }}</span>
                                </li>
                            @endif
                            @if($supplyJob->delivery_date)
                                <li class="list-group-item">
                                    <i class="fas fa-truck text-success"></i> <strong>Delivery</strong>
                                    <span class="float-right text-muted">{{ \Carbon\Carbon::parse($supplyJob->delivery_date)->format('M d, Y') }}</span>
                                </li>
                            @endif
                            @if($supplyJob->return_date)
                                <li class="list-group-item">
                                    <i class="fas fa-undo text-warning"></i> <strong>Return</strong>
                                    <span class="float-right text-muted">{{ \Carbon\Carbon::parse($supplyJob->return_date)->format('M d, Y') }}</span>
                                </li>
                            @endif
                            @if($supplyJob->unpacking_date)
                                <li class="list-group-item">
                                    <i class="fas fa-box-open text-info"></i> <strong>Unpacking</strong>
                                    <span class="float-right text-muted">{{ \Carbon\Carbon::parse($supplyJob->unpacking_date)->format('M d, Y') }}</span>
                                </li>
                            @endif
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Offered Products -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-box"></i> Offered Products</h3>
                </div>
                <div class="card-body">
                    @if($supplyJob->products->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Brand</th>
                                        <th>Category</th>
                                        <th>Sub-Category</th>
                                        <th>Offered Quantity</th>
                                        <th>Price Per Unit</th>
                                        <th>Total Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($supplyJob->products as $supplyProduct)
                                        <tr>
                                            <td>
                                                @if($supplyProduct->product)
                                                    <strong>{{ $supplyProduct->product->model }}</strong>
                                                    @if($supplyProduct->product->psm_code)
                                                        <br><small class="text-muted">PSM: {{ $supplyProduct->product->psm_code }}</small>
                                                    @endif
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($supplyProduct->product && $supplyProduct->product->brand)
                                                    <span class="badge badge-success">{{ $supplyProduct->product->brand->name }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($supplyProduct->product && $supplyProduct->product->category)
                                                    <span class="badge badge-primary">{{ $supplyProduct->product->category->name }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($supplyProduct->product && $supplyProduct->product->subCategory)
                                                    <span class="badge badge-info">{{ $supplyProduct->product->subCategory->name }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge badge-warning">{{ $supplyProduct->offered_quantity ?? 'N/A' }}</span>
                                            </td>
                                            <td>
                                                @if($supplyProduct->price_per_unit)
                                                    <strong>${{ number_format($supplyProduct->price_per_unit, 2) }}</strong>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($supplyProduct->price_per_unit && $supplyProduct->offered_quantity)
                                                    <strong class="text-success">${{ number_format($supplyProduct->price_per_unit * $supplyProduct->offered_quantity, 2) }}</strong>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @if($supplyJob->products->count() > 0)
                                    <tfoot>
                                        <tr class="bg-light">
                                            <td colspan="6" class="text-right"><strong>Total:</strong></td>
                                            <td>
                                                <strong class="text-success">
                                                    ${{ number_format($supplyJob->products->sum(function($p) {
                                                        return ($p->price_per_unit ?? 0) * ($p->offered_quantity ?? 0);
                                                    }), 2) }}
                                                </strong>
                                            </td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>
                    @else
                        <p class="text-muted">No products offered yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Rental Job Context -->
    @if($supplyJob->rentalJob)
        <div class="row">
            <div class="col-md-12">
                <div class="card card-outline card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-briefcase"></i> Related Rental Job Information</h3>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-3">Rental Job Name</dt>
                            <dd class="col-sm-9"><strong>{{ $supplyJob->rentalJob->name }}</strong></dd>

                            <dt class="col-sm-3">Rental Period</dt>
                            <dd class="col-sm-9">
                                @if($supplyJob->rentalJob->from_date && $supplyJob->rentalJob->to_date)
                                    <i class="fas fa-calendar-check text-success"></i> {{ \Carbon\Carbon::parse($supplyJob->rentalJob->from_date)->format('M d, Y') }}
                                    <i class="fas fa-arrow-right"></i>
                                    <i class="fas fa-calendar-times text-danger"></i> {{ \Carbon\Carbon::parse($supplyJob->rentalJob->to_date)->format('M d, Y') }}
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3">Delivery Address</dt>
                            <dd class="col-sm-9">{{ $supplyJob->rentalJob->delivery_address ?? 'N/A' }}</dd>

                            <dt class="col-sm-3">Requested Products</dt>
                            <dd class="col-sm-9">
                                <span class="badge badge-primary">{{ $supplyJob->rentalJob->products->count() }}</span>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Comments -->
    @if($supplyJob->comments->count() > 0)
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-comments"></i> Comments</h3>
                    </div>
                    <div class="card-body">
                        @foreach($supplyJob->comments as $comment)
                            <div class="card mb-2">
                                <div class="card-body p-2">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong>
                                                @if($comment->sender)
                                                    {{ $comment->sender->username }}
                                                @else
                                                    Unknown User
                                                @endif
                                            </strong>
                                            @if($comment->recipient)
                                                <i class="fas fa-arrow-right text-muted"></i>
                                                <strong>{{ $comment->recipient->username }}</strong>
                                            @endif
                                        </div>
                                        <small class="text-muted">{{ $comment->created_at?->format('M d, Y H:i') }}</small>
                                    </div>
                                    <p class="mb-0 mt-1">{{ $comment->message }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
@stop

