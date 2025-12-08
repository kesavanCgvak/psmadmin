@extends('adminlte::page')

@section('title', 'Subscription Details')

@section('content_header')
    <div class="row align-items-center">
        <div class="col">
            <h1 class="m-0">Subscription Details</h1>
        </div>
        <div class="col-auto">
            <a href="{{ route('admin.subscriptions.index') }}" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
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

    <div class="row">
        <!-- User Information Card -->
        <div class="col-md-4">
            <div class="card card-primary card-outline">
                <div class="card-body box-profile">
                    <div class="text-center">
                        @if($subscription->user->profile?->profile_picture && file_exists(public_path($subscription->user->profile->profile_picture)))
                            <img class="profile-user-img img-fluid img-circle img-bordered-sm"
                                 src="{{ asset($subscription->user->profile->profile_picture) }}"
                                 alt="{{ $subscription->user->username }}'s profile picture"
                                 style="object-fit: cover; width: 100px; height: 100px;"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        @endif
                        <div class="profile-user-img img-fluid img-circle img-bordered-sm bg-primary d-flex align-items-center justify-content-center text-white mx-auto {{ $subscription->user->profile?->profile_picture && file_exists(public_path($subscription->user->profile->profile_picture)) ? 'd-none' : '' }}"
                             style="width: 100px; height: 100px; font-size: 36px; font-weight: bold;">
                            {{ strtoupper(substr($subscription->user->username, 0, 1)) }}
                        </div>
                    </div>

                    <h3 class="profile-username text-center">{{ $subscription->user->profile->full_name ?? $subscription->user->username }}</h3>

                    <p class="text-muted text-center">
                        @if($subscription->account_type === 'provider')
                            <span class="badge badge-primary">Provider</span>
                        @else
                            <span class="badge badge-info">User</span>
                        @endif
                    </p>

                    <ul class="list-group list-group-unbordered mb-3">
                        <li class="list-group-item">
                            <b>Username</b> <a class="float-right">{{ $subscription->user->username }}</a>
                        </li>
                        <li class="list-group-item">
                            <b>Email</b> <a class="float-right">{{ $subscription->user->profile->email ?? $subscription->user->email }}</a>
                        </li>
                        @if($subscription->user->company)
                            <li class="list-group-item">
                                <b>Company</b> <a class="float-right">{{ $subscription->user->company->name }}</a>
                            </li>
                        @endif
                        <li class="list-group-item">
                            <b>Account Type</b> 
                            <a class="float-right">
                                @if($subscription->account_type === 'provider')
                                    <span class="badge badge-primary">Provider</span>
                                @else
                                    <span class="badge badge-info">User</span>
                                @endif
                            </a>
                        </li>
                    </ul>

                    <a href="{{ route('admin.users.show', $subscription->user->id) }}" class="btn btn-primary btn-block">
                        <i class="fas fa-user"></i> View User Profile
                    </a>
                </div>
            </div>
        </div>

        <!-- Subscription Details Card -->
        <div class="col-md-8">
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-credit-card"></i> Subscription Information
                    </h3>
                    <div class="card-tools">
                        @if($subscription->stripe_status === 'active')
                            <span class="badge badge-success badge-lg">Active</span>
                        @elseif($subscription->stripe_status === 'trialing')
                            <span class="badge badge-primary badge-lg">Trialing</span>
                        @elseif($subscription->stripe_status === 'past_due')
                            <span class="badge badge-warning badge-lg">Past Due</span>
                        @elseif($subscription->stripe_status === 'unpaid')
                            <span class="badge badge-danger badge-lg">Unpaid</span>
                        @elseif($subscription->stripe_status === 'canceled')
                            <span class="badge badge-secondary badge-lg">Canceled</span>
                        @else
                            <span class="badge badge-secondary badge-lg">{{ ucfirst($subscription->stripe_status) }}</span>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Basic Information</h5>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Subscription ID:</th>
                                    <td>
                                        {{ $subscription->id }}
                                        <button class="btn btn-sm btn-link p-0 ml-2" onclick="copyToClipboard('{{ $subscription->id }}')" title="Copy">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Stripe Subscription ID:</th>
                                    <td>
                                        <a href="{{ $stripeSubscriptionUrl }}" target="_blank" class="text-primary">
                                            {{ $subscription->stripe_subscription_id }}
                                        </a>
                                        <button class="btn btn-sm btn-link p-0 ml-2" onclick="copyToClipboard('{{ $subscription->stripe_subscription_id }}')" title="Copy">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Stripe Customer ID:</th>
                                    <td>
                                        <a href="{{ $stripeCustomerUrl }}" target="_blank" class="text-primary">
                                            {{ $subscription->stripe_customer_id }}
                                        </a>
                                        <button class="btn btn-sm btn-link p-0 ml-2" onclick="copyToClipboard('{{ $subscription->stripe_customer_id }}')" title="Copy">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Plan Name:</th>
                                    <td><strong>{{ $subscription->plan_name }}</strong></td>
                                </tr>
                                <tr>
                                    <th>Plan Type:</th>
                                    <td>{{ ucfirst($subscription->plan_type) }}</td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
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
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h5>Pricing Information</h5>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Amount:</th>
                                    <td><strong>{{ $subscription->currency }} {{ number_format($subscription->amount, 2) }}</strong></td>
                                </tr>
                                <tr>
                                    <th>Currency:</th>
                                    <td>{{ strtoupper($subscription->currency) }}</td>
                                </tr>
                                <tr>
                                    <th>Billing Interval:</th>
                                    <td>{{ ucfirst($subscription->interval) }}</td>
                                </tr>
                                <tr>
                                    <th>Stripe Price ID:</th>
                                    <td>
                                        <code>{{ $subscription->stripe_price_id }}</code>
                                        <button class="btn btn-sm btn-link p-0 ml-2" onclick="copyToClipboard('{{ $subscription->stripe_price_id }}')" title="Copy">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <h5>Timeline</h5>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Created Date:</th>
                                    <td>{{ $subscription->created_at->format('M d, Y h:i A') }}</td>
                                </tr>
                                @if($subscription->trial_ends_at)
                                    <tr>
                                        <th>Trial End Date:</th>
                                        <td>
                                            {{ $subscription->trial_ends_at->format('M d, Y h:i A') }}<br>
                                            <small class="text-muted">{{ $subscription->trial_ends_at->diffForHumans() }}</small>
                                            @if($daysUntilTrialEnd !== null)
                                                <br><small class="text-info"><strong>{{ $daysUntilTrialEnd }} days remaining</strong></small>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                                @if($subscription->current_period_start)
                                    <tr>
                                        <th>Current Period Start:</th>
                                        <td>
                                            {{ $subscription->current_period_start->format('M d, Y h:i A') }}<br>
                                            <small class="text-muted">{{ $subscription->current_period_start->diffForHumans() }}</small>
                                        </td>
                                    </tr>
                                @endif
                                @if($subscription->current_period_end)
                                    <tr>
                                        <th>Current Period End:</th>
                                        <td>
                                            {{ $subscription->current_period_end->format('M d, Y h:i A') }}<br>
                                            <small class="text-muted">{{ $subscription->current_period_end->diffForHumans() }}</small>
                                            @if($daysUntilPeriodEnd !== null)
                                                <br><small class="text-info"><strong>{{ $daysUntilPeriodEnd }} days remaining</strong></small>
                                            @endif
                                        </td>
                                    </tr>
                                @endif
                                @if($subscription->ends_at)
                                    <tr>
                                        <th>Subscription End Date:</th>
                                        <td>
                                            {{ $subscription->ends_at->format('M d, Y h:i A') }}<br>
                                            <small class="text-muted">{{ $subscription->ends_at->diffForHumans() }}</small>
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <th>Last Updated:</th>
                                    <td>{{ $subscription->updated_at->format('M d, Y h:i A') }}</td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-6">
                            <h5>Status Details</h5>
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Is Active:</th>
                                    <td>
                                        @if($subscription->isActive())
                                            <span class="badge badge-success">Yes</span>
                                        @else
                                            <span class="badge badge-danger">No</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Is Trialing:</th>
                                    <td>
                                        @if($subscription->isOnTrial())
                                            <span class="badge badge-primary">Yes</span>
                                        @else
                                            <span class="badge badge-secondary">No</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Payment Failed:</th>
                                    <td>
                                        @if($subscription->isPaymentFailed())
                                            <span class="badge badge-danger">Yes</span>
                                        @else
                                            <span class="badge badge-success">No</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Is Canceled:</th>
                                    <td>
                                        @if($subscription->isCanceled())
                                            <span class="badge badge-warning">Yes</span>
                                        @else
                                            <span class="badge badge-success">No</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Is Past Due:</th>
                                    <td>
                                        @if($subscription->isPastDue())
                                            <span class="badge badge-warning">Yes</span>
                                        @else
                                            <span class="badge badge-success">No</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-12">
                            <h5>Actions</h5>
                            <div class="btn-group" role="group">
                                <a href="{{ $stripeSubscriptionUrl }}" target="_blank" class="btn btn-warning">
                                    <i class="fab fa-stripe"></i> View in Stripe Dashboard
                                </a>
                                <a href="{{ $stripeCustomerUrl }}" target="_blank" class="btn btn-secondary">
                                    <i class="fas fa-user-circle"></i> View Customer in Stripe
                                </a>
                                <form method="POST" action="{{ route('admin.subscriptions.sync', $subscription->id) }}" style="display: inline;" id="syncForm">
                                    @csrf
                                    <button type="submit" class="btn btn-info" id="syncBtn">
                                        <i class="fas fa-sync"></i> Sync with Stripe
                                    </button>
                                </form>
                                <a href="{{ route('admin.users.show', $subscription->user->id) }}" class="btn btn-primary">
                                    <i class="fas fa-user"></i> View User Profile
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    <style>
        .badge-lg {
            font-size: 1rem;
            padding: 0.5em 0.75em;
        }

        .table-sm th {
            font-weight: 600;
        }

        .table-sm td code {
            font-size: 0.875rem;
        }

        .btn-group .btn {
            margin-right: 5px;
        }

        @media (max-width: 768px) {
            .btn-group {
                flex-direction: column;
            }

            .btn-group .btn {
                margin-bottom: 5px;
                margin-right: 0;
            }
        }
    </style>
@stop

@section('js')
    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                // Show temporary feedback
                alert('Copied to clipboard: ' + text);
            }, function(err) {
                console.error('Failed to copy: ', err);
            });
        }

        // Handle sync form submission
        document.getElementById('syncForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const btn = document.getElementById('syncBtn');
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing...';
            
            fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json',
                },
                body: new FormData(form)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('Subscription synced successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Failed to sync subscription'));
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while syncing. Please try again.');
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });
    </script>
@stop

