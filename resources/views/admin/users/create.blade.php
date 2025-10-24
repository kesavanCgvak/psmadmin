@extends('adminlte::page')

@section('title', 'Create User')

@section('content_header')
    <h1>Create New User</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-12">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Information</h3>
                </div>
                <form action="{{ route('admin.users.store') }}" method="POST" enctype="multipart/form-data" id="userCreateForm">
                    @csrf
                    <div class="card-body">
                        <!-- Company Selection with Add New Link -->
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="company_id">Company <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <select class="form-control @error('company_id') is-invalid @enderror"
                                                id="company_id" name="company_id" required>
                                            <option value="">Select Company</option>
                                            @foreach($companies as $company)
                                                <option value="{{ $company->id }}"
                                                    {{ (old('company_id') == $company->id || (isset($selectedCompanyId) && $selectedCompanyId == $company->id)) ? 'selected' : '' }}
                                                    data-country-id="{{ $company->country_id }}"
                                                    data-state-id="{{ $company->state_id }}"
                                                    data-account-type="{{ $company->account_type }}">
                                                    {{ $company->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-primary" id="addCompanyBtn">
                                                <i class="fas fa-plus"></i> Add New Company
                                            </button>
                                        </div>
                                        @error('company_id')
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <small class="form-text text-muted" id="phoneFormatHint"></small>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="username">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('username') is-invalid @enderror"
                                           id="username" name="username" value="{{ old('username') }}" required>
                                    <div class="invalid-feedback" id="usernameError">
                                        @error('username')
                                            {{ $message }}
                                        @else
                                            Username is required.
                                        @enderror
                                    </div>
                                    <div class="valid-feedback" id="usernameSuccess" style="display: none;">
                                        Username is available.
                                    </div>
                                    <small class="form-text text-muted">
                                        <span id="usernameStatus"><i class="fas fa-info-circle"></i> Enter a unique username</span>
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="account_type">Account Type <span class="text-danger">*</span></label>
                                    <select class="form-control @error('account_type') is-invalid @enderror"
                                            id="account_type" name="account_type" required readonly>
                                        <option value="">Auto-assigned based on company</option>
                                        <option value="Provider" {{ old('account_type') === 'Provider' ? 'selected' : '' }}>Provider</option>
                                        <option value="User" {{ old('account_type') === 'User' ? 'selected' : '' }}>User</option>
                                    </select>
                                    @error('account_type')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <small class="form-text text-muted">
                                        Account type is automatically assigned based on the selected company
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="role">Role in Company <span class="text-danger">*</span></label>
                                    <select class="form-control @error('role') is-invalid @enderror"
                                            id="role" name="role" required>
                                        <option value="">Select Role</option>
                                        <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                                        <option value="user" {{ old('role') === 'user' ? 'selected' : '' }}>User</option>
                                    </select>
                                    @error('role')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <small class="form-text text-muted">
                                        Admin = Full access | User = Limited access
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="password">Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                                               id="password" name="password" required>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                <i class="fas fa-eye" id="togglePasswordIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                    @error('password')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="mt-2">
                                        <small class="form-text">
                                            <strong>Password Strength:</strong>
                                            <div class="progress" style="height: 5px;">
                                                <div class="progress-bar" id="passwordStrengthBar" role="progressbar" style="width: 0%"></div>
                                            </div>
                                            <span id="passwordStrengthText" class="text-muted">Enter password (min. 8 characters)</span>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="password_confirmation">Confirm Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control"
                                           id="password_confirmation" name="password_confirmation" required>
                                    <small class="form-text" id="passwordMatchText"></small>
                                </div>
                            </div>
                        </div>


                        <hr>

                        <h5>Profile Information</h5>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="full_name">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('full_name') is-invalid @enderror"
                                           id="full_name" name="full_name" value="{{ old('full_name') }}" required>
                                    @error('full_name')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="email">Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                                           id="email" name="email" value="{{ old('email') }}" required>
                                    @error('email')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="mobile">Mobile Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('mobile') is-invalid @enderror"
                                           id="mobile" name="mobile" value="{{ old('mobile') }}" required>
                                    @error('mobile')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <small class="form-text text-muted" id="mobileFormatHint">
                                        Select a company first to see the format
                                    </small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="birthday">Birthday <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control @error('birthday') is-invalid @enderror"
                                           id="birthday" name="birthday" value="{{ old('birthday') }}" required
                                           max="{{ date('Y-m-d', strtotime('-18 years')) }}">
                                    @error('birthday')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <small class="form-text text-muted" id="ageValidation">
                                        Must be at least 18 years old
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="profile_picture">Profile Picture</label>
                                    <input type="file" class="form-control-file @error('profile_picture') is-invalid @enderror"
                                           id="profile_picture" name="profile_picture" accept="image/*">
                                    @error('profile_picture')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <div class="form-check" style="margin-top: 38px;">
                                        <input type="checkbox" class="form-check-input" id="email_verified" name="email_verified" value="1"
                                               {{ old('email_verified') ? 'checked' : '' }}>
                                        <label class="form-check-label" for="email_verified">
                                            Email Verified
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-save"></i> Create User
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
            font-size: 1.25rem;
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
        }

        select.form-control {
            font-size: 0.875rem;
        }

        .input-group-append .btn {
            font-size: 0.875rem;
            padding: 0.375rem 0.75rem;
        }

        .input-group-append .btn span {
            display: none;
        }

        .btn {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
        }

        small.form-text {
            font-size: 0.75rem;
        }

        .invalid-feedback,
        .valid-feedback {
            font-size: 0.75rem;
        }

        .progress {
            height: 4px !important;
        }

        .alert {
            font-size: 0.875rem;
            padding: 0.75rem 1rem;
        }
    }

    @media (min-width: 577px) and (max-width: 768px) {
        .content-header h1 {
            font-size: 1.5rem;
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

    /* ========== Input Group Responsiveness ========== */
    @media (max-width: 576px) {
        .input-group {
            flex-wrap: nowrap;
        }

        .input-group .form-control {
            min-width: 0;
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
    }

    /* ========== Password Strength Bar ========== */
    .progress {
        height: 5px;
        margin-top: 0.5rem;
    }

    @media (max-width: 576px) {
        #passwordStrengthText {
            font-size: 0.75rem;
        }
    }

    /* ========== Success Alert ========== */
    .alert {
        border-radius: 0.25rem;
        margin-bottom: 1rem;
    }

    @media (max-width: 576px) {
        .alert .close {
            padding: 0.5rem;
            font-size: 1.25rem;
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

    /* ========== Better Touch Targets ========== */
    @media (max-width: 768px) {
        .btn {
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-control,
        select.form-control {
            min-height: 44px;
        }

        .form-check-input {
            width: 20px;
            height: 20px;
            margin-top: 0.15rem;
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

    /* ========== Readonly Select Styling ========== */
    select[readonly] {
        background-color: #e9ecef;
        cursor: not-allowed;
        pointer-events: none;
    }

    select[readonly]:focus {
        border-color: #ced4da;
        box-shadow: none;
    }
</style>
@stop

@section('js')
<script>
$(document).ready(function() {
    let usernameCheckTimeout;
    let formData = {};

    // Save form data to localStorage
    function saveFormData() {
        formData = {
            username: $('#username').val(),
            account_type: $('#account_type').val(),
            full_name: $('#full_name').val(),
            email: $('#email').val(),
            mobile: $('#mobile').val(),
            birthday: $('#birthday').val(),
            email_verified: $('#email_verified').is(':checked')
        };
        localStorage.setItem('userFormData', JSON.stringify(formData));
    }

    // Restore form data from localStorage
    function restoreFormData() {
        const savedData = localStorage.getItem('userFormData');
        if (savedData) {
            formData = JSON.parse(savedData);
            $('#username').val(formData.username || '');
            $('#account_type').val(formData.account_type || '');
            $('#full_name').val(formData.full_name || '');
            $('#email').val(formData.email || '');
            $('#mobile').val(formData.mobile || '');
            $('#birthday').val(formData.birthday || '');
            $('#email_verified').prop('checked', formData.email_verified || false);
        }
    }

    // Clear form data from localStorage
    function clearFormData() {
        localStorage.removeItem('userFormData');
    }

    // Restore form data when page loads (if coming back from company create)
    @if(isset($selectedCompanyId) && $selectedCompanyId)
        restoreFormData();
    @endif

    // Save form data before leaving to create company
    $('#addCompanyBtn').on('click', function() {
        saveFormData();
        window.location.href = "{{ route('admin.companies.create', ['return_to_user_create' => 1]) }}";
    });

    // Clear saved data on successful form submission
    $('#userCreateForm').on('submit', function() {
        clearFormData();
    });

    // Username validation (real-time)
    $('#username').on('input', function() {
        const username = $(this).val();
        const $input = $(this);
        const $status = $('#usernameStatus');

        clearTimeout(usernameCheckTimeout);

        if (username.length === 0) {
            $input.removeClass('is-valid is-invalid');
            $status.html('<i class="fas fa-info-circle"></i> Enter a unique username');
            return;
        }

        $status.html('<i class="fas fa-spinner fa-spin"></i> Checking...');

        usernameCheckTimeout = setTimeout(function() {
            $.ajax({
                url: "{{ route('admin.ajax.check-username') }}",
                method: 'GET',
                data: { username: username },
                success: function(response) {
                    if (response.available) {
                        $input.removeClass('is-invalid').addClass('is-valid');
                        $status.html('<i class="fas fa-check-circle text-success"></i> ' + response.message);
                    } else {
                        $input.removeClass('is-valid').addClass('is-invalid');
                        $status.html('<i class="fas fa-times-circle text-danger"></i> ' + response.message);
                    }
                }
            });
        }, 500);
    });

    // Password strength validation
    $('#password').on('input', function() {
        const password = $(this).val();
        const $bar = $('#passwordStrengthBar');
        const $text = $('#passwordStrengthText');

        let strength = 0;
        let strengthText = '';
        let strengthClass = '';

        if (password.length === 0) {
            $bar.css('width', '0%').removeClass().addClass('progress-bar');
            $text.text('Enter password (min. 8 characters)').removeClass();
            return;
        }

        // Calculate strength
        if (password.length >= 8) strength += 25;
        if (password.match(/[a-z]/)) strength += 25;
        if (password.match(/[A-Z]/)) strength += 25;
        if (password.match(/[0-9]/)) strength += 15;
        if (password.match(/[^a-zA-Z0-9]/)) strength += 10;

        // Set strength text and color
        if (strength < 40) {
            strengthText = 'Weak';
            strengthClass = 'bg-danger';
        } else if (strength < 70) {
            strengthText = 'Medium';
            strengthClass = 'bg-warning';
        } else {
            strengthText = 'Strong';
            strengthClass = 'bg-success';
        }

        $bar.css('width', strength + '%').removeClass().addClass('progress-bar ' + strengthClass);
        $text.text(strengthText).removeClass().addClass(strengthClass.replace('bg-', 'text-'));
    });

    // Password confirmation validation
    $('#password_confirmation').on('input', function() {
        const password = $('#password').val();
        const confirmation = $(this).val();
        const $text = $('#passwordMatchText');

        if (confirmation.length === 0) {
            $text.text('');
            $(this).removeClass('is-valid is-invalid');
            return;
        }

        if (password === confirmation) {
            $(this).removeClass('is-invalid').addClass('is-valid');
            $text.html('<i class="fas fa-check-circle text-success"></i> Passwords match').addClass('text-success');
        } else {
            $(this).removeClass('is-valid').addClass('is-invalid');
            $text.html('<i class="fas fa-times-circle text-danger"></i> Passwords do not match').addClass('text-danger');
        }
    });

    // Birthday age validation
    $('#birthday').on('change', function() {
        const birthday = new Date($(this).val());
        const today = new Date();
        const age = Math.floor((today - birthday) / (365.25 * 24 * 60 * 60 * 1000));
        const $validation = $('#ageValidation');

        if (age < 18) {
            $(this).addClass('is-invalid');
            $validation.html('<i class="fas fa-times-circle text-danger"></i> Must be at least 18 years old').addClass('text-danger');
        } else {
            $(this).removeClass('is-invalid').addClass('is-valid');
            $validation.html('<i class="fas fa-check-circle text-success"></i> Age: ' + age + ' years').addClass('text-success');
        }
    });

    // Company selection - fetch phone format and auto-assign account type
    $('#company_id').on('change', function() {
        const companyId = $(this).val();
        const $hint = $('#phoneFormatHint');
        const $mobileHint = $('#mobileFormatHint');
        const $accountType = $('#account_type');
        const selectedOption = $(this).find('option:selected');

        if (!companyId) {
            $hint.text('');
            $mobileHint.text('Select a company first to see the format');
            $accountType.val('');
            return;
        }

        // Auto-assign account type immediately from data attribute
        const accountType = selectedOption.data('account-type');
        console.log('Company selected, account type from data attribute:', accountType);

        if (accountType) {
            // Set the value and mark as selected
            $accountType.val(accountType);
            $accountType.find('option[value="' + accountType + '"]').prop('selected', true);
            $accountType.removeClass('is-invalid').addClass('is-valid');
            console.log('Account type set to:', accountType);
        }

        // Fetch company details and phone format
        $.ajax({
            url: "{{ url('admin/ajax/company') }}/" + companyId + "/phone-format",
            method: 'GET',
            success: function(response) {
                console.log('AJAX response:', response);

                if (response.phone_format) {
                    $hint.html('<i class="fas fa-info-circle"></i> Company location: ' +
                        (response.country || 'N/A') +
                        (response.state ? ', ' + response.state : ''));
                    $mobileHint.html('<i class="fas fa-phone"></i> Format: <code>' + response.phone_format + '</code>');
                }

                // Auto-assign account type based on company (fallback from AJAX)
                if (response.account_type && !accountType) {
                    console.log('Setting account type from AJAX response:', response.account_type);
                    $accountType.val(response.account_type);
                    $accountType.find('option[value="' + response.account_type + '"]').prop('selected', true);
                    $accountType.removeClass('is-invalid').addClass('is-valid');
                }
            },
            error: function(xhr) {
                console.error('Error fetching company details:', xhr);
                // Account type should already be set from data attribute
            }
        });
    });

    // Password visibility toggle
    $('#togglePassword').on('click', function() {
        const passwordField = $('#password');
        const passwordIcon = $('#togglePasswordIcon');

        if (passwordField.attr('type') === 'password') {
            passwordField.attr('type', 'text');
            passwordIcon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            passwordField.attr('type', 'password');
            passwordIcon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    // Trigger company change event on page load if a company is already selected
    if ($('#company_id').val()) {
        $('#company_id').trigger('change');
    }

    // Trigger phone format load if company is pre-selected
    @if(isset($selectedCompanyId) && $selectedCompanyId)
        $('#company_id').trigger('change');
    @endif
});
</script>
@stop
