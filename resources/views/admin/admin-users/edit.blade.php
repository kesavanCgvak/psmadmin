@extends('adminlte::page')

@section('title', 'Edit Super Admin User')

@section('content_header')
    <h1>Edit Super Admin User</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">Edit: {{ $adminUser->username }}</h3>
                </div>
                <form action="{{ route('admin.admin-users.update', $adminUser) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="card-body">
                        @if($errors->any())
                            <div class="alert alert-danger alert-dismissible">
                                <button type="button" class="close" data-dismiss="alert">&times;</button>
                                <h5><i class="icon fas fa-ban"></i> Validation Error!</h5>
                                <ul class="mb-0">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="form-group">
                            <label for="username">Username (Optional)</label>
                            <input type="text"
                                   class="form-control @error('username') is-invalid @enderror"
                                   id="username"
                                   name="username"
                                   value="{{ old('username', $adminUser->username) }}"
                                   placeholder="Leave empty to auto-generate from email">
                            @error('username')
                                <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">If left empty, will be auto-generated from email address.</small>
                        </div>

                        <div class="form-group">
                            <label for="full_name">Full Name <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control @error('full_name') is-invalid @enderror"
                                   id="full_name"
                                   name="full_name"
                                   value="{{ old('full_name', $adminUser->profile?->full_name) }}"
                                   required
                                   placeholder="Enter full name">
                            @error('full_name')
                                <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address <span class="text-danger">*</span></label>
                            <input type="email"
                                   class="form-control @error('email') is-invalid @enderror"
                                   id="email"
                                   name="email"
                                   value="{{ old('email', $adminUser->profile?->email ?? $adminUser->email) }}"
                                   required
                                   placeholder="admin@example.com">
                            @error('email')
                                <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">
                                <strong>This email is used for login.</strong> Must be unique among Super Admins.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="mobile">Mobile Number</label>
                            <input type="text"
                                   class="form-control @error('mobile') is-invalid @enderror"
                                   id="mobile"
                                   name="mobile"
                                   value="{{ old('mobile', $adminUser->profile?->mobile) }}"
                                   placeholder="+1234567890">
                            @error('mobile')
                                <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="role">Admin Role <span class="text-danger">*</span></label>
                            <input type="hidden" name="role" value="super_admin">
                            <input type="text"
                                   class="form-control"
                                   value="Super Admin"
                                   disabled
                                   readonly>
                            @error('role')
                                <span class="invalid-feedback d-block">{{ $message }}</span>
                            @enderror
                            @if($adminUser->profile?->email === 'kesavan@cgvak.com' || $adminUser->email === 'kesavan@cgvak.com')
                                <small class="form-text text-warning">
                                    <i class="fas fa-lock"></i> Primary Super Admin role cannot be changed.
                                </small>
                            @else
                                <small class="form-text text-muted">
                                    All admin users are Super Admins with full system access.
                                </small>
                            @endif
                        </div>

                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox"
                                       class="custom-control-input"
                                       id="is_blocked"
                                       name="is_blocked"
                                       value="1"
                                       {{ old('is_blocked', $adminUser->is_blocked) ? 'checked' : '' }}
                                       @if($adminUser->profile?->email === 'kesavan@cgvak.com' || $adminUser->email === 'kesavan@cgvak.com') disabled @endif>
                                <label class="custom-control-label" for="is_blocked">
                                    Block this admin user
                                </label>
                            </div>
                            <small class="form-text text-muted">Blocked users cannot log into the admin panel.</small>
                        </div>

                        @if($adminUser->profile?->email !== 'kesavan@cgvak.com' && $adminUser->email !== 'kesavan@cgvak.com')
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Note:</strong> Password cannot be changed here. Use the "Reset Password" button in the admin user details page to generate and send a new password to the user.
                            </div>
                        @endif
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-save"></i> Update Super Admin User
                        </button>
                        <a href="{{ route('admin.admin-users.show', $adminUser) }}" class="btn btn-info">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                        <a href="{{ route('admin.admin-users.index') }}" class="btn btn-default">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">Account Information</h3>
                </div>
                <div class="card-body">
                    <dl>
                        <dt>User ID</dt>
                        <dd>{{ $adminUser->id }}</dd>

                        <dt>Account Type</dt>
                        <dd><span class="badge badge-dark">{{ $adminUser->account_type ?? 'admin' }}</span></dd>

                        <dt>Current Status</dt>
                        <dd>
                            @if($adminUser->is_blocked)
                                <span class="badge badge-danger">Blocked</span>
                            @else
                                <span class="badge badge-success">Active</span>
                            @endif
                        </dd>

                        <dt>Email Verified</dt>
                        <dd>
                            @if($adminUser->email_verified)
                                <span class="badge badge-success">
                                    <i class="fas fa-check"></i> Yes
                                </span>
                            @else
                                <span class="badge badge-warning">
                                    <i class="fas fa-times"></i> No
                                </span>
                            @endif
                        </dd>

                        <dt>Created</dt>
                        <dd>{{ $adminUser->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt>Last Updated</dt>
                        <dd>{{ $adminUser->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
            </div>

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
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-check-circle"></i> Reactivate Account
                                </button>
                            </form>
                        @else
                            @if($adminUser->id !== auth()->id())
                                <form action="{{ route('admin.admin-users.destroy', $adminUser) }}" method="POST" onsubmit="return confirm('Are you sure you want to deactivate this admin user?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-block">
                                        <i class="fas fa-ban"></i> Deactivate Account
                                    </button>
                                </form>
                            @endif
                        @endif
                    @else
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-shield-alt"></i> Primary Super Admin account is protected from deletion and password reset.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('js')
    @include('partials.responsive-js')
@stop

