@extends('adminlte::page')

@section('title', 'Super Admin User Details')

@section('content_header')
    <h1>Super Admin User Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
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
        <div class="col-md-8">
            <!-- Admin User Information -->
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $adminUser->username }}</h3>
                    <div class="card-tools">
                        @php
                            $roleColors = [
                                'super_admin' => 'danger',
                                'admin' => 'primary',
                            ];
                            $roleColor = $roleColors[$adminUser->role] ?? 'secondary';
                        @endphp
                        <span class="badge badge-{{ $roleColor }}">
                            {{ ucfirst(str_replace('_', ' ', $adminUser->role)) }}
                        </span>
                        @if($adminUser->profile?->email === 'kesavan@cgvak.com' || $adminUser->email === 'kesavan@cgvak.com')
                            <span class="badge badge-warning">Primary Super Admin</span>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-3">User ID</dt>
                        <dd class="col-sm-9">{{ $adminUser->id }}</dd>

                        <dt class="col-sm-3">Username</dt>
                        <dd class="col-sm-9"><strong>{{ $adminUser->username }}</strong></dd>

                        <dt class="col-sm-3">Full Name</dt>
                        <dd class="col-sm-9">{{ $adminUser->profile?->full_name ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Email Address</dt>
                        <dd class="col-sm-9">
                            @if($adminUser->profile?->email)
                                <a href="mailto:{{ $adminUser->profile->email }}">{{ $adminUser->profile->email }}</a>
                            @elseif($adminUser->email)
                                <a href="mailto:{{ $adminUser->email }}">{{ $adminUser->email }}</a>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Mobile Number</dt>
                        <dd class="col-sm-9">{{ $adminUser->profile?->mobile ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Role</dt>
                        <dd class="col-sm-9">
                            <span class="badge badge-{{ $roleColor }}">
                                {{ ucfirst(str_replace('_', ' ', $adminUser->role)) }}
                            </span>
                        </dd>

                        <dt class="col-sm-3">Account Type</dt>
                        <dd class="col-sm-9">
                            <span class="badge badge-dark">{{ $adminUser->account_type ?? 'admin' }}</span>
                        </dd>

                        <dt class="col-sm-3">Status</dt>
                        <dd class="col-sm-9">
                            @if($adminUser->is_blocked)
                                <span class="badge badge-danger"><i class="fas fa-ban"></i> Blocked</span>
                            @else
                                <span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Email Verified</dt>
                        <dd class="col-sm-9">
                            @if($adminUser->email_verified)
                                <span class="badge badge-success">
                                    <i class="fas fa-check"></i> Yes
                                </span>
                                @if($adminUser->email_verified_at)
                                    <br><small class="text-muted">Verified on {{ $adminUser->email_verified_at->format('M d, Y H:i:s') }}</small>
                                @endif
                            @else
                                <span class="badge badge-warning">
                                    <i class="fas fa-times"></i> No
                                </span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Created At</dt>
                        <dd class="col-sm-9">{{ $adminUser->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-3">Updated At</dt>
                        <dd class="col-sm-9">{{ $adminUser->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    @if($isSuperAdmin)
                        <a href="{{ route('admin.admin-users.edit', $adminUser) }}" class="btn btn-warning">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    @endif
                    <a href="{{ route('admin.admin-users.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Quick Actions -->
            @if($isSuperAdmin)
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title">Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        @if($adminUser->profile?->email !== 'kesavan@cgvak.com' && $adminUser->email !== 'kesavan@cgvak.com')
                            <form action="{{ route('admin.admin-users.reset-password', $adminUser) }}" method="POST" onsubmit="return confirm('Are you sure you want to reset the password? A new password will be sent to the user\'s email.');">
                                @csrf
                                <button type="submit" class="btn btn-warning btn-block mb-2">
                                    <i class="fas fa-key"></i> Reset Password
                                </button>
                            </form>

                            @if($adminUser->is_blocked)
                                <form action="{{ route('admin.admin-users.reactivate', $adminUser) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-block mb-2">
                                        <i class="fas fa-check-circle"></i> Reactivate Account
                                    </button>
                                </form>
                            @else
                                @if($adminUser->id !== auth()->id())
                                    <form action="{{ route('admin.admin-users.destroy', $adminUser) }}" method="POST" onsubmit="return confirm('Are you sure you want to deactivate this admin user?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-block mb-2">
                                            <i class="fas fa-ban"></i> Deactivate Account
                                        </button>
                                    </form>
                                @endif
                            @endif

                            <a href="{{ route('admin.admin-users.edit', $adminUser) }}" class="btn btn-primary btn-block">
                                <i class="fas fa-edit"></i> Edit Details
                            </a>
                        @else
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-shield-alt"></i> Primary Super Admin account is protected from deletion and password reset.
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Permissions -->
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">Permissions & Access</h3>
                </div>
                <div class="card-body">
                    <h5 class="text-danger"><i class="fas fa-crown"></i> Super Admin</h5>
                    <ul class="mb-0">
                        <li>Full system access</li>
                        <li>Manage all users and companies</li>
                        <li>Create/edit/delete Super Admin users</li>
                        <li>Manage products and equipment</li>
                        <li>View and manage all jobs</li>
                        <li>Access all reports and analytics</li>
                        <li>Complete administrative control</li>
                        <li>System configuration</li>
                    </ul>
                </div>
            </div>

            <!-- Activity Summary -->
            <div class="card card-success">
                <div class="card-header">
                    <h3 class="card-title">Account Summary</h3>
                </div>
                <div class="card-body">
                    <div class="info-box bg-light">
                        <span class="info-box-icon bg-primary"><i class="fas fa-calendar-alt"></i></span>
                        <div class="info-box-content">
                            <span class="info-box-text">Account Age</span>
                            <span class="info-box-number">
                                {{ $adminUser->created_at?->diffForHumans() ?? 'N/A' }}
                            </span>
                        </div>
                    </div>

                    <div class="info-box bg-light">
                        <span class="info-box-icon bg-{{ $adminUser->is_blocked ? 'danger' : 'success' }}">
                            <i class="fas fa-{{ $adminUser->is_blocked ? 'ban' : 'check-circle' }}"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">Account Status</span>
                            <span class="info-box-number">
                                {{ $adminUser->is_blocked ? 'Blocked' : 'Active' }}
                            </span>
                        </div>
                    </div>

                    <div class="info-box bg-light">
                        <span class="info-box-icon bg-{{ $adminUser->email_verified ? 'success' : 'warning' }}">
                            <i class="fas fa-{{ $adminUser->email_verified ? 'check' : 'times' }}"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">Email Status</span>
                            <span class="info-box-number">
                                {{ $adminUser->email_verified ? 'Verified' : 'Not Verified' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop

