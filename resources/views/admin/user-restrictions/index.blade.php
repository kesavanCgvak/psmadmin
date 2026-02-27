@extends('adminlte::page')

@section('title', 'User Restrictions')

@section('content_header')
    <h1>User Restrictions / System Settings</h1>
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

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h5><i class="icon fas fa-ban"></i> Validation Error!</h5>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i> Company User Limit Settings
                    </h3>
                </div>
                <form action="{{ route('admin.user-restrictions.update') }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <div class="card-body">
                        <div class="form-group">
                            <label for="company_user_limit">Maximum Users Per Company <span class="text-danger">*</span></label>
                            <input 
                                type="number" 
                                class="form-control @error('company_user_limit') is-invalid @enderror" 
                                id="company_user_limit" 
                                name="company_user_limit"
                                value="{{ old('company_user_limit', $userLimit) }}"
                                min="1"
                                max="100"
                                required
                            >
                            @error('company_user_limit')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">
                                Set the maximum number of users that each company can create. This limit applies to all companies.
                            </small>
                        </div>

                        <div class="alert alert-info">
                            <h5><i class="icon fas fa-info"></i> Current Setting</h5>
                            <p class="mb-1">
                                <strong>Maximum Users Per Company:</strong> 
                                <span class="badge badge-primary">{{ $userLimit }}</span>
                            </p>
                            <p class="mb-0">
                                ✓ This limit is enforced when creating new users<br>
                                ✓ Changes apply immediately without code deployment<br>
                                ✓ All companies are subject to this limit
                            </p>
                        </div>

                        <div class="alert alert-warning">
                            <h5><i class="icon fas fa-exclamation-triangle"></i> Important Notes</h5>
                            <ul class="mb-0">
                                <li>Changing this setting affects <strong>all companies</strong> immediately.</li>
                                <li>Companies that have already reached the limit cannot create new users.</li>
                                <li>Companies below the limit can create users up to the new limit.</li>
                                <li>This setting is enforced in both the Admin Panel and API.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="{{ route('dashboard') }}" class="btn btn-default">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-question-circle"></i> Quick Help
                    </h3>
                </div>
                <div class="card-body">
                    <h5>How It Works:</h5>
                    <ul>
                        <li>Each company can create up to <strong>{{ $userLimit }} users</strong></li>
                        <li>When limit is reached, user creation is blocked</li>
                        <li>Admin panel shows warning when limit is reached</li>
                        <li>API returns error when limit is exceeded</li>
                    </ul>

                    <hr>

                    <h5>Enforcement Points:</h5>
                    <ul>
                        <li>Admin Panel → Create User</li>
                        <li>API → Company User Creation</li>
                        <li>Login API Response (shows limit info)</li>
                    </ul>

                    <hr>

                    <h5>Example:</h5>
                    <p class="mb-0">
                        If limit is set to <strong>5</strong>:<br>
                        • Company A can create 5 users<br>
                        • Company B can create 5 users<br>
                        • Each company is independent
                    </p>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
<script>
    $(document).ready(function() {
        // Validate input on change
        $('#company_user_limit').on('input', function() {
            const value = parseInt($(this).val());
            if (value < 1) {
                $(this).val(1);
            } else if (value > 100) {
                $(this).val(100);
            }
        });
    });
</script>
@stop

