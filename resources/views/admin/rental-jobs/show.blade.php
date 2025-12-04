@extends('adminlte::page')

@section('title', 'Rental Job Details')

@section('content_header')
    <h1>Rental Job Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <!-- Job Information -->
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $rentalJob->name }}</h3>
                    <div class="card-tools">
                        @php
                            $statusColors = [
                                'open' => 'info',
                                'in_negotiation' => 'primary',
                                'partially_accepted' => 'warning',
                                'accepted' => 'success',
                                'closed' => 'secondary',
                                'cancelled' => 'danger',
                                'completed' => 'success',
                            ];
                            $statusColor = $statusColors[$rentalJob->status] ?? 'secondary';
                            $statusDisplay = ucfirst(str_replace('_', ' ', $rentalJob->status ?? 'N/A'));
                        @endphp
                        <span class="badge badge-{{ $statusColor }}">{{ $statusDisplay }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">Job ID</dt>
                        <dd class="col-sm-9">{{ $rentalJob->id }}</dd>

                        <dt class="col-sm-3">Job Name</dt>
                        <dd class="col-sm-9"><strong>{{ $rentalJob->name }}</strong></dd>

                        <dt class="col-sm-3">Created By</dt>
                        <dd class="col-sm-9">
                            @if($rentalJob->user)
                                <div>
                                    <strong>{{ $rentalJob->user->username }}</strong>
                                    @if($rentalJob->user->profile)
                                        <br><small class="text-muted">{{ $rentalJob->user->profile->email ?? 'No email' }}</small>
                                        @if($rentalJob->user->profile->phone)
                                            <br><small class="text-muted"><i class="fas fa-phone"></i> {{ $rentalJob->user->profile->phone }}</small>
                                        @endif
                                    @endif
                                </div>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Company</dt>
                        <dd class="col-sm-9">
                            @if($rentalJob->user && $rentalJob->user->company)
                                <a href="{{ route('admin.companies.show', $rentalJob->user->company) }}" class="badge badge-info">
                                    {{ $rentalJob->user->company->name }}
                                </a>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Rental Period</dt>
                        <dd class="col-sm-9">
                            @if($rentalJob->from_date && $rentalJob->to_date)
                                <div>
                                    <i class="fas fa-calendar-check text-success"></i> <strong>From:</strong> {{ \Carbon\Carbon::parse($rentalJob->from_date)->format('M d, Y') }}
                                    <br>
                                    <i class="fas fa-calendar-times text-danger"></i> <strong>To:</strong> {{ \Carbon\Carbon::parse($rentalJob->to_date)->format('M d, Y') }}
                                    <br>
                                    <small class="text-muted">
                                        ({{ \Carbon\Carbon::parse($rentalJob->from_date)->diffInDays(\Carbon\Carbon::parse($rentalJob->to_date)) }} days)
                                    </small>
                                </div>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Delivery Address</dt>
                        <dd class="col-sm-9">{{ $rentalJob->delivery_address ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Offer Requirements</dt>
                        <dd class="col-sm-9">{{ $rentalJob->offer_requirements ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Global Message</dt>
                        <dd class="col-sm-9">{{ $rentalJob->global_message ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            <span class="badge badge-{{ $statusColor }}">{{ $statusDisplay }}</span>
                        </dd>

                        <dt class="col-sm-3">Fulfilled Quantity</dt>
                        <dd class="col-sm-9">
                            <span class="badge badge-info">{{ $rentalJob->fulfilled_quantity ?? 0 }}</span>
                            @if($rentalJob->total_requested_quantity)
                                / <span class="text-muted">{{ $rentalJob->total_requested_quantity }}</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Created At</dt>
                        <dd class="col-sm-9">{{ $rentalJob->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-3">Updated At</dt>
                        <dd class="col-sm-9">{{ $rentalJob->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.rental-jobs.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Statistics -->
            <div class="card card-widget widget-user-2">
                <div class="widget-user-header bg-primary">
                    <h3 class="widget-user-username">Job Statistics</h3>
                </div>
                <div class="card-footer p-0">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <span class="nav-link">
                                Products <span class="float-right badge badge-primary">{{ $rentalJob->products->count() }}</span>
                            </span>
                        </li>
                        <li class="nav-item">
                            <span class="nav-link">
                                Supply Jobs <span class="float-right badge badge-success">{{ $rentalJob->supplyJobs->count() }}</span>
                            </span>
                        </li>
                        <li class="nav-item">
                            <span class="nav-link">
                                Comments <span class="float-right badge badge-info">{{ $rentalJob->comments->count() }}</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Requested Products -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-box"></i> Requested Products</h3>
                </div>
                <div class="card-body">
                    @if($rentalJob->products->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Brand</th>
                                        <th>Category</th>
                                        <th>Sub-Category</th>
                                        <th>Requested</th>
                                        <th>Fulfilled</th>
                                        <th>Status</th>
                                        <th>Assigned Company</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rentalJob->products as $rentalProduct)
                                        <tr>
                                            <td>
                                                @if($rentalProduct->product)
                                                    <strong>{{ $rentalProduct->product->model }}</strong>
                                                    @if($rentalProduct->product->psm_code)
                                                        <br><small class="text-muted">PSM: {{ $rentalProduct->product->psm_code }}</small>
                                                    @endif
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($rentalProduct->product && $rentalProduct->product->brand)
                                                    <span class="badge badge-success">{{ $rentalProduct->product->brand->name }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($rentalProduct->product && $rentalProduct->product->category)
                                                    <span class="badge badge-primary">{{ $rentalProduct->product->category->name }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($rentalProduct->product && $rentalProduct->product->subCategory)
                                                    <span class="badge badge-info">{{ $rentalProduct->product->subCategory->name }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge badge-warning">{{ $rentalProduct->requested_quantity ?? 0 }}</span>
                                            </td>
                                            <td>
                                                <span class="badge badge-success">{{ $rentalProduct->fulfilled_quantity ?? 0 }}</span>
                                            </td>
                                            <td>
                                                @php
                                                    $productStatusColors = [
                                                        'pending' => 'warning',
                                                        'partially_fulfilled' => 'info',
                                                        'fulfilled' => 'success',
                                                        'cancelled' => 'danger',
                                                    ];
                                                    $productStatusColor = $productStatusColors[$rentalProduct->status] ?? 'secondary';
                                                    $productStatusDisplay = ucfirst(str_replace('_', ' ', $rentalProduct->status ?? 'pending'));
                                                @endphp
                                                <span class="badge badge-{{ $productStatusColor }}">{{ $productStatusDisplay }}</span>
                                            </td>
                                            <td>
                                                @if($rentalProduct->company)
                                                    <a href="{{ route('admin.companies.show', $rentalProduct->company) }}" class="badge badge-info">
                                                        {{ $rentalProduct->company->name }}
                                                    </a>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">No products requested yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Supply Jobs -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-truck"></i> Supply Jobs</h3>
                </div>
                <div class="card-body">
                    @if($rentalJob->supplyJobs->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Provider</th>
                                        <th>Status</th>
                                        <th>Handshake Status</th>
                                        <th>Quote Price</th>
                                        <th>Accepted Price</th>
                                        <th>Products</th>
                                        <th>Dates</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rentalJob->supplyJobs as $supplyJob)
                                        <tr>
                                            <td>{{ $supplyJob->id }}</td>
                                            <td>
                                                @if($supplyJob->provider)
                                                    <a href="{{ route('admin.companies.show', $supplyJob->provider) }}" class="badge badge-info">
                                                        {{ $supplyJob->provider->name }}
                                                    </a>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @php
                                                    $supplyStatusColors = [
                                                        'pending' => 'warning',
                                                        'negotiating' => 'info',
                                                        'partially_accepted' => 'warning',
                                                        'accepted' => 'success',
                                                        'closed' => 'secondary',
                                                        'cancelled' => 'danger',
                                                        'completed' => 'success',
                                                    ];
                                                    $supplyStatusColor = $supplyStatusColors[$supplyJob->status] ?? 'secondary';
                                                    $supplyStatusDisplay = ucfirst(str_replace('_', ' ', $supplyJob->status ?? 'N/A'));
                                                @endphp
                                                <span class="badge badge-{{ $supplyStatusColor }}">{{ $supplyStatusDisplay }}</span>
                                            </td>
                                            <td>
                                                @if($supplyJob->handshake_status)
                                                    @php
                                                        $handshakeColors = [
                                                            'pending_user' => 'warning',
                                                            'pending_provider' => 'info',
                                                            'accepted' => 'success',
                                                            'cancelled' => 'danger',
                                                        ];
                                                        $handshakeColor = $handshakeColors[$supplyJob->handshake_status] ?? 'secondary';
                                                        $handshakeDisplay = ucfirst(str_replace('_', ' ', $supplyJob->handshake_status));
                                                    @endphp
                                                    <span class="badge badge-{{ $handshakeColor }}">{{ $handshakeDisplay }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($supplyJob->quote_price)
                                                    <strong>${{ number_format($supplyJob->quote_price, 2) }}</strong>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($supplyJob->accepted_price)
                                                    <strong class="text-success">${{ number_format($supplyJob->accepted_price, 2) }}</strong>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge badge-primary">{{ $supplyJob->products->count() }}</span>
                                            </td>
                                            <td>
                                                <small>
                                                    @if($supplyJob->packing_date)
                                                        <i class="fas fa-box text-primary"></i> Pack: {{ \Carbon\Carbon::parse($supplyJob->packing_date)->format('M d') }}<br>
                                                    @endif
                                                    @if($supplyJob->delivery_date)
                                                        <i class="fas fa-truck text-success"></i> Deliver: {{ \Carbon\Carbon::parse($supplyJob->delivery_date)->format('M d') }}<br>
                                                    @endif
                                                    @if($supplyJob->return_date)
                                                        <i class="fas fa-undo text-warning"></i> Return: {{ \Carbon\Carbon::parse($supplyJob->return_date)->format('M d') }}<br>
                                                    @endif
                                                </small>
                                            </td>
                                            <td>
                                                <a href="{{ route('admin.supply-jobs.show', $supplyJob) }}" class="btn btn-info btn-sm" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted">No supply jobs created yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Job Offers -->
    @if($rentalJob->offers && $rentalJob->offers->count() > 0)
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-handshake"></i> Job Offers (All Supply Jobs)</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Supply Job</th>
                                        <th>Version</th>
                                        <th>Sender</th>
                                        <th>Receiver</th>
                                        <th>Total Price</th>
                                        <th>Currency</th>
                                        <th>Status</th>
                                        <th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rentalJob->offers->sortByDesc('version') as $offer)
                                        <tr>
                                            <td>
                                                @if($offer->supply_job_id)
                                                    <a href="{{ route('admin.supply-jobs.show', $offer->supply_job_id) }}" class="badge badge-info">
                                                        #{{ $offer->supply_job_id }}
                                                    </a>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td><span class="badge badge-info">{{ $offer->version }}</span></td>
                                            <td>
                                                @if($offer->senderCompany)
                                                    <span class="badge badge-primary">{{ $offer->senderCompany->name }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($offer->receiverCompany)
                                                    <span class="badge badge-secondary">{{ $offer->receiverCompany->name }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                <strong>${{ number_format($offer->total_price, 2) }}</strong>
                                            </td>
                                            <td>
                                                @if($offer->currency)
                                                    <span class="badge badge-info">{{ $offer->currency->code }}</span>
                                                @else
                                                    <span class="text-muted">N/A</span>
                                                @endif
                                            </td>
                                            <td>
                                                @php
                                                    $offerStatusColors = [
                                                        'pending' => 'warning',
                                                        'accepted' => 'success',
                                                        'rejected' => 'danger',
                                                        'cancelled' => 'danger',
                                                    ];
                                                    $offerStatusColor = $offerStatusColors[$offer->status] ?? 'secondary';
                                                    $offerStatusDisplay = ucfirst($offer->status ?? 'N/A');
                                                @endphp
                                                <span class="badge badge-{{ $offerStatusColor }}">{{ $offerStatusDisplay }}</span>
                                            </td>
                                            <td>{{ $offer->created_at?->format('M d, Y H:i') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Comments -->
    @if($rentalJob->comments->count() > 0)
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-comments"></i> Comments</h3>
                    </div>
                    <div class="card-body">
                        @foreach($rentalJob->comments as $comment)
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

