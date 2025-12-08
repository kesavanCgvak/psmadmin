@extends('adminlte::page')

@section('title', 'Payment Settings')

@section('content_header')
    <h1>Payment Settings</h1>
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
                        <i class="fas fa-credit-card"></i> Payment Requirement Settings
                    </h3>
                </div>
                <form action="{{ route('admin.payment-settings.update') }}" method="POST">
                    @csrf
                    @method('PUT')
                    
                    <div class="card-body">
                        <div class="form-group">
                            <label for="payment_enabled">Payment Requirement Status</label>
                            <div class="custom-control custom-switch">
                                <input 
                                    type="checkbox" 
                                    class="custom-control-input" 
                                    id="payment_enabled" 
                                    name="payment_enabled"
                                    value="1"
                                    {{ $paymentEnabled ? 'checked' : '' }}
                                >
                                <label class="custom-control-label" for="payment_enabled">
                                    <span id="status-text">{{ $paymentEnabled ? 'Enabled' : 'Disabled' }}</span>
                                </label>
                            </div>
                            <small class="form-text text-muted">
                                <strong>Enabled:</strong> All new user registrations will require a credit card and subscription.<br>
                                <strong>Disabled:</strong> Users can register without payment. No subscription will be created.
                            </small>
                        </div>

                        <div class="alert alert-info">
                            <h5><i class="icon fas fa-info"></i> Current Status</h5>
                            <p class="mb-1">
                                <strong>Payment Requirement:</strong> 
                                <span id="current-status" class="badge badge-{{ $paymentEnabled ? 'success' : 'warning' }}">
                                    {{ $paymentEnabled ? 'ENABLED' : 'DISABLED' }}
                                </span>
                            </p>
                            @if($paymentEnabled)
                                <p class="mb-0">
                                    ✓ Credit card required during registration<br>
                                    ✓ Subscription created with trial period<br>
                                    ✓ Automatic billing after trial ends
                                </p>
                            @else
                                <p class="mb-0">
                                    ✓ No credit card required<br>
                                    ✓ No subscription created<br>
                                    ✓ Free registration and access
                                </p>
                            @endif
                        </div>

                        <div class="alert alert-warning">
                            <h5><i class="icon fas fa-exclamation-triangle"></i> Important Notes</h5>
                            <ul class="mb-0">
                                <li>Changing this setting only affects <strong>new registrations</strong>.</li>
                                <li>Existing users and subscriptions are not affected.</li>
                                <li>When disabled, existing subscriptions continue to work normally.</li>
                                <li>When enabled, users must provide valid credit card during registration.</li>
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
                    <h5>When Payment is Enabled:</h5>
                    <ul>
                        <li>Providers: $99/month (60-day trial)</li>
                        <li>Users: $2.99/month (14-day trial)</li>
                        <li>Credit card required on registration</li>
                        <li>Subscription created automatically</li>
                    </ul>

                    <hr>

                    <h5>When Payment is Disabled:</h5>
                    <ul>
                        <li>No payment required</li>
                        <li>No subscription created</li>
                        <li>Users can register freely</li>
                        <li>Useful for testing or promotions</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
<script>
    $(document).ready(function() {
        // Update status text when toggle changes
        $('#payment_enabled').on('change', function() {
            const isEnabled = $(this).is(':checked');
            $('#status-text').text(isEnabled ? 'Enabled' : 'Disabled');
            $('#current-status')
                .text(isEnabled ? 'ENABLED' : 'DISABLED')
                .removeClass('badge-success badge-warning')
                .addClass(isEnabled ? 'badge-success' : 'badge-warning');
        });
    });
</script>
@stop

