@extends('adminlte::page')

@section('title', 'Create Super Admin User')

@section('content_header')
    <h1>Create New Super Admin User</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">Super Admin User Information</h3>
                </div>
                <form action="{{ route('admin.admin-users.store') }}" method="POST">
                    @csrf
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
                                   value="{{ old('username') }}"
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
                                   value="{{ old('full_name') }}"
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
                                   value="{{ old('email') }}"
                                   required
                                   placeholder="admin@example.com">
                            @error('email')
                                <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                            <small class="form-text text-muted">
                                <strong>This email will be used for login.</strong> Login credentials will be sent to this email. Must be unique among Super Admins.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="mobile">Mobile Number</label>
                            <input type="text"
                                   class="form-control @error('mobile') is-invalid @enderror"
                                   id="mobile"
                                   name="mobile"
                                   value="{{ old('mobile') }}"
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
                            <small class="form-text text-muted">
                                <strong>Super Admin:</strong> Full system access including managing other Super Admin users.
                            </small>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Note:</strong> A secure password will be automatically generated and sent to the user's email address along with login instructions.
                        </div>
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Super Admin User
                        </button>
                        <a href="{{ route('admin.admin-users.index') }}" class="btn btn-default">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">Important Information</h3>
                </div>
                <div class="card-body">
                    <h5><i class="fas fa-shield-alt"></i> Security</h5>
                    <ul>
                        <li>Password is automatically generated (12+ characters)</li>
                        <li>Password includes letters, numbers, and special characters</li>
                        <li>User will be prompted to change password on first login</li>
                        <li>Account is automatically verified</li>
                    </ul>

                    <h5 class="mt-3"><i class="fas fa-envelope"></i> Email Notification</h5>
                    <ul>
                        <li>Welcome email sent automatically</li>
                        <li>Includes username and password</li>
                        <li>Contains admin panel URL</li>
                        <li>Lists user's permissions</li>
                    </ul>

                    <h5 class="mt-3"><i class="fas fa-user-shield"></i> Super Admin Role</h5>
                    <p><strong>Super Admin has:</strong></p>
                    <ul>
                        <li>Full system access</li>
                        <li>Manage all users and companies</li>
                        <li>Create/edit/delete Super Admin users</li>
                        <li>Manage products, equipment, and jobs</li>
                        <li>Access all reports and analytics</li>
                        <li>Complete administrative control</li>
                    </ul>
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

