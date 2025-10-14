@extends('adminlte::page')

@section('title', 'Company Details')

@section('content_header')
    <h1>Company Details</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <!-- Company Information -->
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $company->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">ID</dt>
                        <dd class="col-sm-9">{{ $company->id }}</dd>

                        <dt class="col-sm-3">Name</dt>
                        <dd class="col-sm-9"><strong>{{ $company->name }}</strong></dd>

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9">{{ $company->description ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Location</dt>
                        <dd class="col-sm-9">
                            @if($company->region)
                                <span class="badge badge-primary">{{ $company->region->name }}</span>
                            @endif
                            @if($company->country)
                                <span class="badge badge-success">{{ $company->country->name }}</span>
                            @endif
                            @if($company->state)
                                <span class="badge badge-info">{{ $company->state->name }}</span>
                            @endif
                            @if($company->city)
                                <span class="badge badge-warning">{{ $company->city->name }}</span>
                            @endif
                            @if(!$company->region && !$company->country)
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Address</dt>
                        <dd class="col-sm-9">
                            @if($company->address_line_1)
                                {{ $company->address_line_1 }}<br>
                            @endif
                            @if($company->address_line_2)
                                {{ $company->address_line_2 }}<br>
                            @endif
                            @if($company->postal_code)
                                {{ $company->postal_code }}
                            @endif
                            @if(!$company->address_line_1 && !$company->postal_code)
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">GPS Coordinates</dt>
                        <dd class="col-sm-9">
                            @if($company->latitude && $company->longitude)
                                {{ number_format($company->latitude, 6) }}, {{ number_format($company->longitude, 6) }}
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Currency</dt>
                        <dd class="col-sm-9">
                            @if($company->currency)
                                <span class="badge badge-success">{{ $company->currency->code }}</span> {{ $company->currency->name }}
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Rental Software</dt>
                        <dd class="col-sm-9">
                            @if($company->rentalSoftware)
                                <span class="badge badge-info">{{ $company->rentalSoftware->name }}</span>
                                @if($company->rentalSoftware->version)
                                    <small class="text-muted">v{{ $company->rentalSoftware->version }}</small>
                                @endif
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Date Format</dt>
                        <dd class="col-sm-9">{{ $company->date_format ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Pricing Scheme</dt>
                        <dd class="col-sm-9">{{ $company->pricing_scheme ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Search Priority</dt>
                        <dd class="col-sm-9">{{ $company->search_priority ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Rating</dt>
                        <dd class="col-sm-9">
                            @php
                                $rating = $company->rating ?? 0;
                            @endphp
                            @for($i = 1; $i <= 5; $i++)
                                @if($i <= $rating)
                                    <i class="fas fa-star text-warning"></i>
                                @else
                                    <i class="far fa-star text-muted"></i>
                                @endif
                            @endfor
                            ({{ number_format($rating, 1) }})
                        </dd>

                        <dt class="col-sm-3">Created At</dt>
                        <dd class="col-sm-9">{{ $company->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-3">Updated At</dt>
                        <dd class="col-sm-9">{{ $company->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.companies.edit', $company) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.companies.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Statistics -->
            <div class="card card-widget widget-user">
                <div class="widget-user-header bg-info">
                    <h3 class="widget-user-username">{{ $company->name }}</h3>
                    <h5 class="widget-user-desc">Company Statistics</h5>
                </div>
                <div class="widget-user-image">
                    <img class="img-circle elevation-2" src="{{ $company->logo ? asset($company->logo) : asset('vendor/adminlte/dist/img/AdminLTELogo.png') }}" alt="Company Logo">
                </div>
                <div class="card-footer">
                    <div class="row">
                        <div class="col-sm-6 border-right">
                            <div class="description-block">
                                <h5 class="description-header">{{ $company->users->count() }}</h5>
                                <span class="description-text">USERS</span>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="description-block">
                                <h5 class="description-header">{{ $company->equipments->count() }}</h5>
                                <span class="description-text">EQUIPMENT</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Company Users</h3>
                </div>
                <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                    @if($company->users->count() > 0)
                        <ul class="list-group list-group-flush">
                            @foreach($company->users as $user)
                                <li class="list-group-item">
                                    <strong>{{ $user->username }}</strong>
                                    @if($user->is_admin)
                                        <span class="badge badge-success float-right">Admin</span>
                                    @else
                                        <span class="badge badge-info float-right">User</span>
                                    @endif
                                    <br>
                                    <small class="text-muted">{{ $user->profile?->email ?? 'No email' }}</small>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted p-3">No users yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Equipment List -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Company Equipment</h3>
                </div>
                <div class="card-body">
                    @if($company->equipments->count() > 0)
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Brand</th>
                                    <th>Quantity</th>
                                    <th>Price</th>
                                    <th>Software Code</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($company->equipments as $equipment)
                                    <tr>
                                        <td>{{ $equipment->product->model }}</td>
                                        <td><span class="badge badge-success">{{ $equipment->product->brand?->name ?? 'N/A' }}</span></td>
                                        <td><span class="badge badge-primary">{{ $equipment->quantity }}</span></td>
                                        <td>${{ number_format($equipment->price, 2) }}</td>
                                        <td>{{ $equipment->software_code ?? 'N/A' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-muted">No equipment added yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

