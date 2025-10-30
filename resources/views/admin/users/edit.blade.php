@extends('adminlte::page')

@section('title', 'Edit User')

@section('content_header')
    <h1>Edit User: {{ $user->username }}</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Information</h3>
                </div>
                <form action="{{ route('admin.users.update', $user) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="username">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('username') is-invalid @enderror"
                                           id="username" name="username" value="{{ old('username', $user->username) }}" required>
                                    @error('username')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="email">Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                                           id="email" name="email" value="{{ old('email', $user->profile?->email ?? $user->email) }}" required>
                                    @error('email')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="password">Password <small class="text-muted">(Leave blank to keep current password)</small></label>
                                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                                           id="password" name="password">
                                    @error('password')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="password_confirmation">Confirm Password</label>
                                    <input type="password" class="form-control"
                                           id="password_confirmation" name="password_confirmation">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Account Type</label>
                                    <input type="text" id="account_type_display" class="form-control" value="{{ optional($user->company)->account_type ? ucfirst(optional($user->company)->account_type) : 'N/A' }}" disabled>
                                    <small class="form-text text-muted">Derived from the associated company</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="role">Role <span class="text-danger">*</span></label>
                                    <select class="form-control @error('role') is-invalid @enderror"
                                            id="role" name="role" required>
                                        <option value="">Select Role</option>
                                        <option value="user" {{ old('role', $user->role) === 'user' ? 'selected' : '' }}>User</option>
                                        <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>Admin</option>
                                    </select>
                                    @error('role')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="company_id">Company</label>
                                    <select class="form-control @error('company_id') is-invalid @enderror"
                                            id="company_id" name="company_id">
                                        <option value="">Select Company (Optional)</option>
                                        @foreach($companies as $company)
                                            <option value="{{ $company->id }}" data-account-type="{{ $company->account_type }}" {{ old('company_id', $user->company_id) == $company->id ? 'selected' : '' }}>
                                                {{ $company->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('company_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="is_admin" name="is_admin" value="1"
                                               {{ old('is_admin', $user->is_admin) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_admin">
                                            Admin User
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" id="email_verified" name="email_verified" value="1"
                                               {{ old('email_verified', $user->email_verified) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="email_verified">
                                            Verified User
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <h5>Profile Information</h5>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="full_name">Full Name</label>
                                    <input type="text" class="form-control @error('full_name') is-invalid @enderror"
                                           id="full_name" name="full_name" value="{{ old('full_name', $user->profile?->full_name) }}">
                                    @error('full_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="mobile">Mobile</label>
                                    <input type="text" class="form-control @error('mobile') is-invalid @enderror"
                                           id="mobile" name="mobile" value="{{ old('mobile', $user->profile?->mobile) }}">
                                    @error('mobile')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="birthday">Birthday</label>
                                    <input type="date" class="form-control @error('birthday') is-invalid @enderror"
                                           id="birthday" name="birthday" value="{{ old('birthday', $user->profile?->birthday) }}">
                                    @error('birthday')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="profile_picture">Profile Picture</label>
                                    @if($user->profile?->profile_picture)
                                        <div class="mb-2">
                                            <img src="{{ asset($user->profile->profile_picture) }}"
                                                 alt="Current Profile Picture"
                                                 class="img-circle"
                                                 style="width: 50px; height: 50px;">
                                            <small class="text-muted d-block">Current profile picture</small>
                                        </div>
                                    @endif
                                    <input type="file" class="form-control-file @error('profile_picture') is-invalid @enderror"
                                           id="profile_picture" name="profile_picture" accept="image/*">
                                    @error('profile_picture')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update User
                        </button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@stop

@section('css')
<style>
    /* ========== Responsive Form Layout ========== */
    @media (max-width: 576px) {
        .content-header h1 {
            font-size: 1.125rem;
            word-wrap: break-word;
        }

        .card-header .card-title {
            font-size: 1rem;
        }

        .card-body {
            padding: 0.75rem;
        }

        .form-group label {
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .form-control,
        .form-control-file {
            font-size: 0.875rem;
            padding: 0.375rem 0.75rem;
            min-height: 44px;
        }

        select.form-control {
            font-size: 0.875rem;
        }

        .btn {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            min-height: 44px;
        }

        small.form-text {
            font-size: 0.75rem;
        }

        .invalid-feedback,
        .valid-feedback {
            font-size: 0.75rem;
        }

        /* Profile picture preview */
        .img-circle {
            width: 40px !important;
            height: 40px !important;
        }

        .mb-2 {
            margin-bottom: 0.5rem !important;
        }
    }

    @media (min-width: 577px) and (max-width: 768px) {
        .content-header h1 {
            font-size: 1.375rem;
        }

        .form-group label {
            font-size: 0.9rem;
        }

        .form-control {
            font-size: 0.9rem;
        }

        .btn {
            font-size: 0.9rem;
        }

        .img-circle {
            width: 45px !important;
            height: 45px !important;
        }
    }

    /* ========== Better Spacing on Mobile ========== */
    @media (max-width: 768px) {
        .row {
            margin-left: 0;
            margin-right: 0;
        }

        .row > [class*='col-'] {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .card-footer {
            padding: 0.75rem;
        }

        .card-footer .btn {
            width: 100%;
            margin-bottom: 0.5rem;
        }

        .card-footer .btn:last-child {
            margin-bottom: 0;
        }

        hr {
            margin: 1rem 0;
        }

        h5 {
            font-size: 1rem;
            margin-bottom: 0.75rem;
        }
    }

    /* ========== Form Check Boxes ========== */
    @media (max-width: 768px) {
        .form-check {
            margin-bottom: 0.5rem;
        }

        .form-check-label {
            font-size: 0.875rem;
        }

        .form-check-input {
            width: 20px;
            height: 20px;
            margin-top: 0.15rem;
        }
    }

    /* ========== File Input ========== */
    .form-control-file {
        width: 100%;
        display: block;
    }

    @media (max-width: 576px) {
        .form-control-file {
            font-size: 0.75rem;
        }
    }

    /* ========== Profile Picture Section ========== */
    .img-circle {
        border: 2px solid #dee2e6;
        object-fit: cover;
    }

    @media (max-width: 576px) {
        .d-block {
            font-size: 0.75rem;
        }
    }

    /* ========== Card Header on Mobile ========== */
    @media (max-width: 576px) {
        .card-header {
            padding: 0.75rem;
        }

        .card-header .card-title {
            margin-bottom: 0;
        }
    }

    /* ========== Better Icon Spacing ========== */
    .btn i {
        margin-right: 0.25rem;
    }

    @media (max-width: 576px) {
        .btn i {
            margin-right: 0.15rem;
        }
    }

    /* ========== Text Wrapping ========== */
    .content-header h1 {
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    /* ========== Password Note ========== */
    @media (max-width: 576px) {
        label small.text-muted {
            display: block;
            margin-top: 0.25rem;
        }
    }

    /* ========== Medium Screens ========== */
    @media (min-width: 769px) and (max-width: 1024px) {
        .card-body {
            padding: 1rem;
        }

        .form-group label {
            font-size: 0.925rem;
        }

        .form-control {
            font-size: 0.925rem;
        }
    }

    /* ========== Large Desktop ========== */
    @media (min-width: 1025px) {
        .card-body {
            padding: 1.25rem;
        }
    }

    /* ========== Print Styles ========== */
    @media print {
        .card-footer,
        .btn {
            display: none !important;
        }

        .card-body {
            padding: 0;
        }
    }
</style>
@stop

@section('js')
<script>
    (function() {
        const companySelect = document.getElementById('company_id');
        const accountTypeDisplay = document.getElementById('account_type_display');

        function toTitleCase(value) {
            if (!value) return 'N/A';
            return value.charAt(0).toUpperCase() + value.slice(1);
        }

        function updateAccountTypeDisplay() {
            const selected = companySelect.options[companySelect.selectedIndex];
            const type = selected ? selected.getAttribute('data-account-type') : '';
            accountTypeDisplay.value = toTitleCase(type);
        }

        if (companySelect && accountTypeDisplay) {
            companySelect.addEventListener('change', updateAccountTypeDisplay);
        }
    })();
    </script>
@stop
