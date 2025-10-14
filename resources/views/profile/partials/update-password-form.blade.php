<section>
    <p class="text-muted">
        {{ __('Ensure your account is using a long, random password to stay secure.') }}
    </p>

    <form method="post" action="{{ route('password.update') }}" class="mt-3">
        @csrf
        @method('put')

        <div class="form-group">
            <label for="update_password_current_password">{{ __('Current Password') }}</label>
            <input type="password"
                   class="form-control @error('current_password', 'updatePassword') is-invalid @enderror"
                   id="update_password_current_password"
                   name="current_password"
                   autocomplete="current-password">
            @error('current_password', 'updatePassword')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="update_password_password">{{ __('New Password') }}</label>
            <input type="password"
                   class="form-control @error('password', 'updatePassword') is-invalid @enderror"
                   id="update_password_password"
                   name="password"
                   autocomplete="new-password">
            @error('password', 'updatePassword')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <label for="update_password_password_confirmation">{{ __('Confirm Password') }}</label>
            <input type="password"
                   class="form-control @error('password_confirmation', 'updatePassword') is-invalid @enderror"
                   id="update_password_password_confirmation"
                   name="password_confirmation"
                   autocomplete="new-password">
            @error('password_confirmation', 'updatePassword')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-group">
            <button type="submit" class="btn btn-warning">
                <i class="fas fa-key"></i> {{ __('Update Password') }}
            </button>

            @if (session('status') === 'password-updated')
                <span class="text-success ml-2">
                    <i class="fas fa-check"></i> {{ __('Saved.') }}
                </span>
            @endif
        </div>
    </form>
</section>
