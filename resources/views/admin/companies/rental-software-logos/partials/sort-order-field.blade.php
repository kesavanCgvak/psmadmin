{{-- Shared sort-order field validation for create/edit forms. --}}
@php
    $sortOrderValue = $sortOrderValue ?? old('sort_order');
    $usedSortOrders = $usedSortOrders ?? [];
@endphp

<div class="form-group">
    <label for="sort_order">Sort Order <span class="text-danger">*</span></label>
    <input type="number"
           class="form-control @error('sort_order') is-invalid @enderror"
           id="sort_order"
           name="sort_order"
           value="{{ $sortOrderValue }}"
           min="1"
           max="999999"
           step="1"
           required
           inputmode="numeric">
    @error('sort_order')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
    <small class="form-text text-muted">
        Must be a unique whole number starting at 1. Lower numbers appear first in the admin list and public API.
    </small>
    <div class="invalid-feedback d-block" id="sort_order_client_error" style="display: none;"></div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var sortOrderInput = document.getElementById('sort_order');
        if (!sortOrderInput) {
            return;
        }

        var usedSortOrders = @json(array_values($usedSortOrders));
        var clientError = document.getElementById('sort_order_client_error');
        var form = sortOrderInput.closest('form');

        function validateSortOrder() {
            var value = sortOrderInput.value.trim();
            var sortOrder = Number(value);
            var message = '';

            if (value === '' || !Number.isInteger(sortOrder)) {
                message = 'Sort order must be a whole number.';
            } else if (sortOrder < 1) {
                message = 'Sort order must be at least 1.';
            } else if (sortOrder > 999999) {
                message = 'Sort order cannot exceed 999,999.';
            } else if (usedSortOrders.indexOf(sortOrder) !== -1) {
                message = 'This sort order is already used by another logo. Please choose a unique value.';
            }

            if (message) {
                sortOrderInput.setCustomValidity(message);
                if (clientError) {
                    clientError.textContent = message;
                    clientError.style.display = 'block';
                }
                return false;
            }

            sortOrderInput.setCustomValidity('');
            if (clientError) {
                clientError.textContent = '';
                clientError.style.display = 'none';
            }

            return true;
        }

        sortOrderInput.addEventListener('input', validateSortOrder);
        sortOrderInput.addEventListener('change', validateSortOrder);

        if (form) {
            form.addEventListener('submit', function(event) {
                if (!validateSortOrder()) {
                    event.preventDefault();
                    sortOrderInput.reportValidity();
                }
            });
        }
    });
</script>
