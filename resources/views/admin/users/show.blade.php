@extends('adminlte::page')

@section('title', 'User Details')

@section('content_header')
    <h1>User Details: {{ $user->username }}</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-md-4">
            <div class="card card-primary card-outline">
                <div class="card-body box-profile">
                    <div class="text-center">
                        @if($user->profile?->profile_picture && file_exists(public_path('storage/' . $user->profile->profile_picture)))
                            <img class="profile-user-img img-fluid img-circle img-bordered-sm"
                                 src="{{ asset('storage/' . $user->profile->profile_picture) }}"
                                 alt="{{ $user->username }}'s profile picture"
                                 style="object-fit: cover;"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        @endif
                        <div class="profile-user-img img-fluid img-circle img-bordered-sm bg-primary d-flex align-items-center justify-content-center text-white mx-auto {{ $user->profile?->profile_picture && file_exists(public_path('storage/' . $user->profile->profile_picture)) ? 'd-none' : '' }}"
                             style="width: 100px; height: 100px; font-size: 36px; font-weight: bold;">
                            {{ strtoupper(substr($user->username, 0, 1)) }}
                        </div>
                    </div>

                    <h3 class="profile-username text-center">{{ $user->profile?->full_name ?? $user->username }}</h3>

                    <p class="text-muted text-center">
                        @if($user->role === 'super_admin')
                            <span class="badge badge-danger">Super Admin</span>
                        @elseif($user->role === 'admin')
                            <span class="badge badge-success">Admin</span>
                        @else
                            <span class="badge badge-secondary">User</span>
                        @endif
                    </p>

                    <ul class="list-group list-group-unbordered mb-3">
                        <li class="list-group-item">
                            <b>Username</b> <a class="float-right">{{ $user->username }}</a>
                        </li>
                        <li class="list-group-item">
                            <b>Email</b> <a class="float-right">{{ $user->profile?->email ?? $user->email }}</a>
                        </li>
                        <li class="list-group-item">
                            <b>Account Type</b> <a class="float-right">
                                @if($user->account_type === 'company')
                                    <span class="badge badge-success">Company</span>
                                @elseif($user->account_type === 'individual')
                                    <span class="badge badge-info">Individual</span>
                                @elseif($user->account_type === 'provider')
                                    <span class="badge badge-primary">Provider</span>
                                @else
                                    <span class="badge badge-secondary">{{ $user->account_type ? ucfirst($user->account_type) : 'N/A' }}</span>
                                @endif
                            </a>
                        </li>
                        <li class="list-group-item">
                            <b>Company</b> <a class="float-right">{{ $user->company?->name ?? 'N/A' }}</a>
                        </li>
                        <li class="list-group-item">
                            <b>Status</b> <a class="float-right">
                                <span class="badge badge-{{ $user->email_verified ? 'success' : 'warning' }}">
                                    {{ $user->email_verified ? 'Verified' : 'Unverified' }}
                                </span>
                            </a>
                        </li>
                        <li class="list-group-item">
                            <b>Member Since</b> <a class="float-right">{{ $user->created_at?->format('M d, Y') ?? 'N/A' }}</a>
                        </li>
                    </ul>

                    <div class="row">
                        <div class="col-6">
                            <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary btn-block">
                                <i class="fas fa-edit"></i> Edit User
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary btn-block">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12 mb-2">
                            <form method="POST" action="{{ route('admin.users.toggle-verification', $user) }}" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn btn-{{ $user->email_verified ? 'warning' : 'success' }} btn-block">
                                    <i class="fas fa-{{ $user->email_verified ? 'times' : 'check' }}"></i>
                                    {{ $user->email_verified ? 'Unverify User' : 'Verify User' }}
                                </button>
                            </form>
                        </div>

                        @if($user->role !== 'super_admin')
                            <div class="col-12 mb-2">
                                <form method="POST" action="{{ route('admin.users.toggle-admin', $user) }}" style="display: inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-{{ $user->is_admin ? 'secondary' : 'primary' }} btn-block">
                                        <i class="fas fa-user-shield"></i>
                                        {{ $user->is_admin ? 'Remove Admin' : 'Make Admin' }}
                                    </button>
                                </form>
                            </div>
                        @endif

                        <div class="col-12">
                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                  style="display: inline;"
                                  onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-block">
                                    <i class="fas fa-trash"></i> Delete User
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="card-header p-2">
                    <ul class="nav nav-pills">
                        <li class="nav-item">
                            <a class="nav-link active" href="#profile" data-toggle="tab">Profile Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#activity" data-toggle="tab">Activity</a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content">
                        <div class="active tab-pane" id="profile">
                            <dl class="row">
                                <dt class="col-sm-3">Full Name:</dt>
                                <dd class="col-sm-9">{{ $user->profile?->full_name ?? 'Not set' }}</dd>

                                <dt class="col-sm-3">Mobile:</dt>
                                <dd class="col-sm-9">{{ $user->profile?->mobile ?? 'Not set' }}</dd>

                                <dt class="col-sm-3">Birthday:</dt>
                                <dd class="col-sm-9">
                                    {{ $user->profile?->birthday ? \Carbon\Carbon::parse($user->profile->birthday)->format('M d, Y') : 'Not set' }}
                                </dd>

                            </dl>
                        </div>

                        <div class="tab-pane" id="activity">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-box bg-gradient-info">
                                        <span class="info-box-icon"><i class="fas fa-calendar"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Account Created</span>
                                            <span class="info-box-number">{{ $user->created_at?->format('M d, Y') ?? 'N/A' }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-box bg-gradient-success">
                                        <span class="info-box-icon"><i class="fas fa-clock"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Last Updated</span>
                                            <span class="info-box-number">{{ $user->updated_at?->format('M d, Y') ?? 'N/A' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-box bg-gradient-warning">
                                        <span class="info-box-icon"><i class="fas fa-building"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Company</span>
                                            <span class="info-box-number">{{ $user->company?->name ?? 'None' }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-box bg-gradient-danger">
                                        <span class="info-box-icon"><i class="fas fa-shield-alt"></i></span>
                                        <div class="info-box-content">
                                            <span class="info-box-text">Role</span>
                                            <span class="info-box-number">{{ ucfirst($user->role) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <h5>Account Details</h5>
                            <dl class="row">
                                <dt class="col-sm-4">User ID:</dt>
                                <dd class="col-sm-8">{{ $user->id }}</dd>

                                <dt class="col-sm-4">Is Admin:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge badge-{{ $user->is_admin ? 'success' : 'secondary' }}">
                                        {{ $user->is_admin ? 'Yes' : 'No' }}
                                    </span>
                                </dd>

                                <dt class="col-sm-4">Is Verified:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge badge-{{ $user->email_verified ? 'success' : 'warning' }}">
                                        {{ $user->email_verified ? 'Yes' : 'No' }}
                                    </span>
                                </dd>

                                <dt class="col-sm-4">Email Verified At:</dt>
                                <dd class="col-sm-8">{{ $user->email_verified_at?->format('M d, Y H:i') ?? 'Not verified' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@stop
