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
                        @if($user->profile?->profile_picture && file_exists(public_path($user->profile->profile_picture)))
                            <img class="profile-user-img img-fluid img-circle img-bordered-sm"
                                 src="{{ asset($user->profile->profile_picture) }}"
                                 alt="{{ $user->username }}'s profile picture"
                                 style="object-fit: cover;"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        @endif
                        <div class="profile-user-img img-fluid img-circle img-bordered-sm bg-primary d-flex align-items-center justify-content-center text-white mx-auto {{ $user->profile?->profile_picture && file_exists(public_path($user->profile->profile_picture)) ? 'd-none' : '' }}"
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
                                    {{ $user->profile?->birthday ?? 'Not set' }}
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

@section('css')
<style>
    /* ========== Base Styles ========== */
    .card {
        box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
        margin-bottom: 1rem;
    }

    /* ========== Profile Picture Styles ========== */
    .profile-user-img {
        max-width: 100px;
        border: 3px solid #dee2e6;
    }

    .profile-username {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    /* ========== Mobile Responsive (320px - 576px) ========== */
    @media (max-width: 576px) {
        .content-header h1 {
            font-size: 1.125rem;
            word-wrap: break-word;
        }

        /* Stack columns on mobile */
        .col-md-4,
        .col-md-8 {
            padding-left: 0;
            padding-right: 0;
        }

        .card-body {
            padding: 0.75rem;
        }

        /* Profile section */
        .profile-user-img {
            max-width: 80px;
            height: 80px;
        }

        .profile-username {
            font-size: 1.25rem;
            margin-top: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .text-muted {
            font-size: 0.875rem;
        }

        /* List group */
        .list-group-item {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
        }

        .list-group-item b {
            font-size: 0.875rem;
        }

        .list-group-item .float-right {
            float: none !important;
            display: block;
            margin-top: 0.25rem;
            word-wrap: break-word;
        }

        /* Badge sizes */
        .badge {
            font-size: 0.7rem;
            padding: 0.25em 0.5em;
        }

        /* Buttons */
        .btn-block {
            font-size: 0.875rem;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            min-height: 44px;
        }

        .btn-block i {
            margin-right: 0.25rem;
        }

        /* Card headers */
        .card-header {
            padding: 0.75rem;
        }

        .card-title {
            font-size: 1rem;
            margin-bottom: 0;
        }

        /* Navigation tabs */
        .nav-pills .nav-link {
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem;
        }

        .nav-item {
            flex: 1;
            text-align: center;
        }

        /* Info boxes */
        .info-box {
            margin-bottom: 0.75rem;
            min-height: auto;
        }

        .info-box-icon {
            width: 60px;
            font-size: 1.5rem;
        }

        .info-box-content {
            padding: 0.5rem;
        }

        .info-box-text {
            font-size: 0.75rem;
        }

        .info-box-number {
            font-size: 0.875rem;
        }

        /* Definition list */
        dl.row {
            margin-bottom: 0.5rem;
        }

        .col-sm-3, .col-sm-4 {
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
            font-weight: 600;
        }

        .col-sm-8, .col-sm-9 {
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
        }

        dt.col-sm-3, dt.col-sm-4 {
            margin-bottom: 0;
        }

        dd.col-sm-8, dd.col-sm-9 {
            margin-bottom: 0.5rem;
        }

        /* Quick actions card */
        .row > .col-12 {
            margin-bottom: 0;
        }

        /* Tab content */
        .tab-content {
            padding-top: 0.75rem;
        }

        h5 {
            font-size: 1rem;
            margin-top: 0.75rem;
            margin-bottom: 0.5rem;
        }

        hr {
            margin: 1rem 0;
        }
    }

    /* ========== Tablet (577px - 768px) ========== */
    @media (min-width: 577px) and (max-width: 768px) {
        .content-header h1 {
            font-size: 1.375rem;
        }

        .profile-user-img {
            max-width: 90px;
            height: 90px;
        }

        .profile-username {
            font-size: 1.375rem;
        }

        .list-group-item {
            padding: 0.625rem 0.875rem;
            font-size: 0.9rem;
        }

        .btn-block {
            font-size: 0.9rem;
        }

        .info-box {
            margin-bottom: 0.875rem;
        }

        .info-box-icon {
            width: 70px;
            font-size: 1.75rem;
        }

        .info-box-text {
            font-size: 0.825rem;
        }

        .info-box-number {
            font-size: 0.95rem;
        }

        .nav-pills .nav-link {
            font-size: 0.9rem;
        }

        dt, dd {
            font-size: 0.9rem;
        }
    }

    /* ========== Medium (769px - 1024px) ========== */
    @media (min-width: 769px) and (max-width: 1024px) {
        .card-body {
            padding: 1rem;
        }

        .profile-user-img {
            max-width: 95px;
        }

        .list-group-item {
            font-size: 0.925rem;
        }

        .info-box-text {
            font-size: 0.875rem;
        }
    }

    /* ========== Large Desktop (1025px - 1440px) ========== */
    @media (min-width: 1025px) and (max-width: 1440px) {
        .card-body {
            padding: 1.25rem;
        }
    }

    /* ========== Extra Large (1441px+) ========== */
    @media (min-width: 1441px) {
        .card-body {
            padding: 1.5rem;
        }

        .profile-user-img {
            max-width: 120px;
        }
    }

    /* ========== Text Wrapping & Truncation ========== */
    .list-group-item .float-right {
        word-wrap: break-word;
        overflow-wrap: break-word;
        max-width: 60%;
    }

    @media (max-width: 576px) {
        .list-group-item .float-right {
            max-width: 100%;
        }
    }

    /* ========== Button Improvements ========== */
    .btn i {
        margin-right: 0.25rem;
    }

    @media (max-width: 768px) {
        .btn {
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }

    /* ========== Info Box Responsive ========== */
    @media (max-width: 576px) {
        .col-md-6 {
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }

        .row {
            margin-left: -0.5rem;
            margin-right: -0.5rem;
        }
    }

    /* ========== Navigation Tabs ========== */
    @media (max-width: 576px) {
        .nav-pills {
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .nav-pills::-webkit-scrollbar {
            height: 4px;
        }

        .nav-pills::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 4px;
        }
    }

    /* ========== Form Submit Buttons ========== */
    form {
        display: inline-block;
        width: 100%;
    }

    @media (max-width: 576px) {
        form button {
            width: 100%;
        }
    }

    /* ========== Card Spacing ========== */
    @media (max-width: 576px) {
        .card {
            margin-bottom: 0.75rem;
        }
    }

    /* ========== Profile Section ========== */
    .box-profile {
        text-align: center;
    }

    @media (max-width: 576px) {
        .box-profile .row {
            margin-top: 0.5rem;
        }

        .box-profile .col-6 {
            padding-left: 0.25rem !important;
            padding-right: 0.25rem !important;
        }
    }

    /* ========== Print Styles ========== */
    @media print {
        .card-header,
        .btn,
        form,
        .card-footer {
            display: none !important;
        }

        .card-body {
            padding: 0;
        }

        .info-box {
            page-break-inside: avoid;
        }

        .card {
            box-shadow: none;
            border: 1px solid #dee2e6;
        }
    }

    /* ========== Additional Touch Targets ========== */
    @media (max-width: 768px) and (hover: none) and (pointer: coarse) {
        .nav-link {
            min-height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .list-group-item {
            min-height: 48px;
        }
    }

    /* ========== Accessibility ========== */
    .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }

    /* ========== Loading States ========== */
    .card.loading {
        opacity: 0.6;
        pointer-events: none;
    }
</style>
@stop
