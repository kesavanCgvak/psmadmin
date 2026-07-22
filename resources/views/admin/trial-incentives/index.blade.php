@extends('adminlte::page')

@section('title', 'Trial Incentives')

@section('content_header')
    <div class="row align-items-center">
        <div class="col">
            <h1 class="m-0">Trial Incentive Grants</h1>
        </div>
        <div class="col-auto">
            <a href="{{ route('admin.subscriptions.index') }}" class="btn btn-secondary btn-sm">
                <i class="fas fa-receipt"></i> All Subscriptions
            </a>
        </div>
    </div>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row mb-3">
        <div class="col-lg-3 col-6">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>{{ $stats['companies_rewarded'] }}</h3>
                    <p>Companies Rewarded</p>
                </div>
                <div class="icon">
                    <i class="fas fa-building"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3>{{ $stats['total_bonus_months'] }}</h3>
                    <p>Bonus Months Granted</p>
                </div>
                <div class="icon">
                    <i class="fas fa-gift"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>{{ $stats['total_grants'] }}</h3>
                    <p>Total Grant Events</p>
                </div>
                <div class="icon">
                    <i class="fas fa-award"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-6">
            <div class="small-box bg-warning">
                <div class="inner">
                    <h3>{{ $stats['trialing_providers'] }}</h3>
                    <p>Providers on Trial</p>
                </div>
                <div class="icon">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-primary card-outline mb-3">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-filter"></i> Filters
            </h3>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.trial-incentives.index') }}">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="search">Search</label>
                            <input type="text"
                                   name="search"
                                   id="search"
                                   class="form-control"
                                   placeholder="Company name, email, or username"
                                   value="{{ request('search') }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="date_from">Granted From</label>
                            <input type="date"
                                   name="date_from"
                                   id="date_from"
                                   class="form-control"
                                   value="{{ request('date_from') }}">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="date_to">Granted To</label>
                            <input type="date"
                                   name="date_to"
                                   id="date_to"
                                   class="form-control"
                                   value="{{ request('date_to') }}">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-group mb-3 w-100">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-search"></i> Apply
                            </button>
                        </div>
                    </div>
                </div>
                @if(request()->hasAny(['search', 'date_from', 'date_to', 'company_id']))
                    <a href="{{ route('admin.trial-incentives.index') }}" class="btn btn-sm btn-outline-secondary">
                        Clear filters
                    </a>
                @endif
            </form>
        </div>
    </div>

    <div class="card card-success card-outline mb-4">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-building"></i> Providers Who Earned Free Months
            </h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Owner</th>
                            <th>Qualified Products</th>
                            <th>Bonus Months</th>
                            <th>Total Free Months</th>
                            <th>Trial Ends</th>
                            <th>Last Grant</th>
                            <th>Next Milestone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($companies as $company)
                            @php
                                $progress = $company->incentive_progress ?? [];
                                $owner = $company->provider_owner;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $company->name }}</strong>
                                    <br>
                                    <small class="text-muted">ID #{{ $company->id }}</small>
                                </td>
                                <td>
                                    @if($owner)
                                        {{ $owner->profile->full_name ?? $owner->username }}
                                        <br>
                                        <small class="text-muted">{{ $owner->email }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        {{ $progress['qualified_product_count'] ?? 0 }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-success">
                                        +{{ (int) ($company->total_bonus_months ?? 0) }}
                                    </span>
                                    <br>
                                    <small class="text-muted">{{ $company->grant_count }} grant(s)</small>
                                </td>
                                <td>
                                    {{ $progress['total_free_months_earned'] ?? 0 }}
                                    / {{ $progress['max_total_free_months'] ?? 9 }}
                                </td>
                                <td>
                                    @if(!empty($progress['trial_ends_at']))
                                        {{ \Carbon\Carbon::parse($progress['trial_ends_at'])->format('M d, Y') }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($company->last_granted_at)
                                        {{ \Carbon\Carbon::parse($company->last_granted_at)->format('M d, Y h:i A') }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if(!empty($progress['next_milestone']))
                                        {{ $progress['next_milestone']['products'] }} products
                                        <br>
                                        <small class="text-muted">
                                            {{ $progress['next_milestone']['products_remaining'] }} remaining
                                            (+{{ $progress['next_milestone']['bonus_months'] }} mo)
                                        </small>
                                    @else
                                        <span class="badge badge-secondary">Max reached</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('admin.trial-incentives.index', array_merge(request()->except('grants_page'), ['company_id' => $company->id])) }}"
                                       class="btn btn-xs btn-outline-primary mb-1"
                                       title="View grants">
                                        <i class="fas fa-list"></i> Grants
                                    </a>
                                    @if($company->subscription)
                                        <a href="{{ route('admin.subscriptions.show', $company->subscription->id) }}"
                                           class="btn btn-xs btn-outline-secondary mb-1"
                                           title="View subscription">
                                            <i class="fas fa-receipt"></i> Sub
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    No trial incentive grants found yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($companies->hasPages())
            <div class="card-footer clearfix">
                {{ $companies->links() }}
            </div>
        @endif
    </div>

    <div class="card card-info card-outline">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-history"></i> Grant History
            </h3>
            @if($filteredCompany)
                <div class="card-tools">
                    <span class="badge badge-primary">
                        Filtered: {{ $filteredCompany->name }}
                    </span>
                    <a href="{{ route('admin.trial-incentives.index', request()->except(['company_id', 'grants_page'])) }}"
                       class="btn btn-tool">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Granted At</th>
                            <th>Company</th>
                            <th>User</th>
                            <th>Milestone</th>
                            <th>Bonus Months</th>
                            <th>Products at Grant</th>
                            <th>Subscription</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($grants as $grant)
                            @php
                                $user = $grant->subscription?->user;
                            @endphp
                            <tr>
                                <td>{{ $grant->granted_at?->format('M d, Y h:i A') }}</td>
                                <td>
                                    <strong>{{ $grant->company?->name ?? '—' }}</strong>
                                </td>
                                <td>
                                    @if($user)
                                        {{ $user->profile->full_name ?? $user->username }}
                                        <br>
                                        <small class="text-muted">{{ $user->email }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-primary">{{ $grant->milestone_products }} products</span>
                                </td>
                                <td>
                                    <span class="badge badge-success">+{{ $grant->bonus_months }}</span>
                                </td>
                                <td>{{ $grant->product_count_at_grant }}</td>
                                <td>
                                    @if($grant->subscription)
                                        <a href="{{ route('admin.subscriptions.show', $grant->subscription_id) }}">
                                            #{{ $grant->subscription_id }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No grant events found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($grants->hasPages())
            <div class="card-footer clearfix">
                {{ $grants->appends(request()->except('grants_page'))->links() }}
            </div>
        @endif
    </div>
@stop
