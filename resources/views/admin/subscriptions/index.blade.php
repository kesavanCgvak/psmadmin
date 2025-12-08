@extends('adminlte::page')

@section('title', 'Subscription Management')

@section('content_header')
    <div class="row align-items-center">
        <div class="col">
            <h1 class="m-0">Subscription Management</h1>
        </div>
    </div>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-check"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-ban"></i> {{ session('error') }}
        </div>
    @endif

    <!-- Statistics Cards -->
    <div class="row mb-3">
        <div class="col-lg-3 col-6">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ $stats['total'] }}</h3>
                    <p>Total Subscriptions</p>
                </div>
                <div class="icon">
                    <i class="fas fa-credit-card"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ $stats['active'] }}</h3>
                    <p>Active Subscriptions</p>
                </div>
                <div class="icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>{{ $stats['trialing'] }}</h3>
                    <p>Trialing</p>
                </div>
                <div class="icon">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-danger">
                <div class="inner">
                    <h3>{{ $stats['payment_failed'] }}</h3>
                    <p>Payment Failed</p>
                </div>
                <div class="icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-lg-6 col-12">
            <div class="small-box bg-warning">
                <div class="inner">
                    <h3>{{ $stats['canceled'] }}</h3>
                    <p>Canceled</p>
                </div>
                <div class="icon">
                    <i class="fas fa-ban"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-12">
            <div class="small-box bg-secondary">
                <div class="inner">
                    <h3>${{ number_format($stats['total_revenue'], 2) }}</h3>
                    <p>Monthly Revenue (Active)</p>
                </div>
                <div class="icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card card-primary card-outline collapsed-card mb-3">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-filter"></i> Filters
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
        </div>
        <div class="card-body" style="display: none;">
            <form method="GET" action="{{ route('admin.subscriptions.index') }}" id="filterForm">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="all" {{ request('status') === 'all' || !request('status') ? 'selected' : '' }}>All Statuses</option>
                                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="trialing" {{ request('status') === 'trialing' ? 'selected' : '' }}>Trialing</option>
                                <option value="past_due" {{ request('status') === 'past_due' ? 'selected' : '' }}>Past Due</option>
                                <option value="unpaid" {{ request('status') === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                                <option value="canceled" {{ request('status') === 'canceled' ? 'selected' : '' }}>Canceled</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="account_type">Account Type</label>
                            <select name="account_type" id="account_type" class="form-control">
                                <option value="all" {{ request('account_type') === 'all' || !request('account_type') ? 'selected' : '' }}>All Types</option>
                                <option value="provider" {{ request('account_type') === 'provider' ? 'selected' : '' }}>Provider</option>
                                <option value="user" {{ request('account_type') === 'user' ? 'selected' : '' }}>User</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="plan">Plan</label>
                            <select name="plan" id="plan" class="form-control">
                                <option value="all" {{ request('plan') === 'all' || !request('plan') ? 'selected' : '' }}>All Plans</option>
                                @foreach($plans as $plan)
                                    <option value="{{ $plan }}" {{ request('plan') === $plan ? 'selected' : '' }}>{{ $plan }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="payment_status">Payment Status</label>
                            <select name="payment_status" id="payment_status" class="form-control">
                                <option value="all" {{ request('payment_status') === 'all' || !request('payment_status') ? 'selected' : '' }}>All</option>
                                <option value="active" {{ request('payment_status') === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="failed" {{ request('payment_status') === 'failed' ? 'selected' : '' }}>Failed</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label for="search">Search</label>
                            <input type="text" name="search" id="search" class="form-control" 
                                   placeholder="Search by username, email, name, company, Stripe ID..." 
                                   value="{{ request('search') }}">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="{{ route('admin.subscriptions.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Subscriptions Table -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Subscriptions List</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="subscriptions-table" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Account Type</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <th>Trial Ends</th>
                            <th>Period End</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($subscriptions as $subscription)
                            <tr>
                                <td>{{ $subscription->id }}</td>
                                <td>
                                    <div>
                                        <strong>{{ $subscription->user->username }}</strong><br>
                                        <small class="text-muted">{{ $subscription->user->profile->full_name ?? $subscription->user->email }}</small><br>
                                        @if($subscription->user->company)
                                            <small class="text-info">{{ $subscription->user->company->name }}</small>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if($subscription->account_type === 'provider')
                                        <span class="badge badge-primary">Provider</span>
                                    @else
                                        <span class="badge badge-info">User</span>
                                    @endif
                                </td>
                                <td>{{ $subscription->plan_name }}</td>
                                <td>
                                    @if($subscription->stripe_status === 'active')
                                        <span class="badge badge-success">Active</span>
                                    @elseif($subscription->stripe_status === 'trialing')
                                        <span class="badge badge-primary">Trialing</span>
                                    @elseif($subscription->stripe_status === 'past_due')
                                        <span class="badge badge-warning">Past Due</span>
                                    @elseif($subscription->stripe_status === 'unpaid')
                                        <span class="badge badge-danger">Unpaid</span>
                                    @elseif($subscription->stripe_status === 'canceled')
                                        <span class="badge badge-secondary">Canceled</span>
                                    @else
                                        <span class="badge badge-secondary">{{ ucfirst($subscription->stripe_status) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $subscription->currency }} {{ number_format($subscription->amount, 2) }}</strong><br>
                                    <small class="text-muted">/ {{ $subscription->interval }}</small>
                                </td>
                                <td>
                                    @if($subscription->trial_ends_at)
                                        {{ $subscription->trial_ends_at->format('M d, Y') }}<br>
                                        <small class="text-muted">{{ $subscription->trial_ends_at->diffForHumans() }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($subscription->current_period_end)
                                        {{ $subscription->current_period_end->format('M d, Y') }}<br>
                                        <small class="text-muted">{{ $subscription->current_period_end->diffForHumans() }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $subscription->created_at->format('M d, Y') }}</td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="{{ route('admin.subscriptions.show', $subscription->id) }}" 
                                           class="btn btn-info btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="https://dashboard.stripe.com/subscriptions/{{ $subscription->stripe_subscription_id }}" 
                                           target="_blank" 
                                           class="btn btn-warning btn-sm" 
                                           title="View in Stripe">
                                            <i class="fab fa-stripe"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center">
                                    <p class="text-muted py-3">No subscriptions found.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($subscriptions->hasPages())
                <div class="d-flex justify-content-center mt-3">
                    {{ $subscriptions->links() }}
                </div>
            @endif
        </div>
    </div>
@stop

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
    <style>
        .small-box {
            border-radius: 0.25rem;
            box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
            display: block;
            margin-bottom: 20px;
            position: relative;
        }

        .small-box > .inner {
            padding: 10px;
        }

        .small-box > .small-box-footer {
            background-color: rgba(0,0,0,.1);
            color: rgba(255,255,255,.8);
            display: block;
            padding: 3px 0;
            position: relative;
            text-align: center;
            text-decoration: none;
            z-index: 10;
        }

        .table-responsive {
            overflow-x: auto;
        }

        #subscriptions-table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            padding: 12px 8px;
            white-space: nowrap;
        }

        #subscriptions-table tbody td {
            padding: 12px 8px;
            vertical-align: middle;
        }

        #subscriptions-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }

        @media (max-width: 768px) {
            .small-box .inner h3 {
                font-size: 1.5rem;
            }

            #subscriptions-table {
                font-size: 0.875rem;
            }

            #subscriptions-table thead th,
            #subscriptions-table tbody td {
                padding: 8px 4px;
            }
        }
    </style>
@stop

@section('js')
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#subscriptions-table').DataTable({
                'paging': false,
                'lengthChange': false,
                'searching': false,
                'ordering': true,
                'info': false,
                'autoWidth': false,
                'order': [[0, 'desc']]
            });
        });
    </script>
@stop

