@extends('adminlte::page')

@section('title', 'Add Rental Software Company Logo')

@section('content_header')
    <h1>Add Rental Software Company Logo</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-info">
        <div class="card-header">
            <h3 class="card-title">Logo Details</h3>
        </div>
        <form action="{{ route('admin.rental-software-company-logos.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="card-body">
                <div class="form-group">
                    <label for="company_name">Company Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control @error('company_name') is-invalid @enderror"
                           id="company_name"
                           name="company_name"
                           value="{{ old('company_name') }}"
                           placeholder="e.g., Rentman, Flex Rental Solutions"
                           maxlength="255"
                           required>
                    @error('company_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="link">Link (URL)</label>
                    <input type="url"
                           class="form-control @error('link') is-invalid @enderror"
                           id="link"
                           name="link"
                           value="{{ old('link') }}"
                           placeholder="https://example.com">
                    @error('link')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <small class="form-text text-muted">Optional. Website URL for this company logo.</small>
                </div>

                <div class="form-group">
                    <label for="logo">Logo Image <span class="text-danger">*</span></label>
                    <div class="custom-file">
                        <input type="file"
                               class="custom-file-input @error('logo') is-invalid @enderror"
                               id="logo"
                               name="logo"
                               accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
                               required>
                        <label class="custom-file-label" for="logo">Choose file</label>
                    </div>
                    @error('logo')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <small class="form-text text-muted">Allowed formats: JPEG, PNG, JPG, GIF, WEBP. Max size: 2 MB.</small>
                </div>

                @include('admin.companies.rental-software-logos.partials.sort-order-field', [
                    'sortOrderValue' => old('sort_order', $defaultSortOrder),
                    'usedSortOrders' => $usedSortOrders,
                ])

                <div class="form-group">
                    <label for="is_active">Active Status</label>
                    <div class="custom-control custom-switch mt-2">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="is_active"
                               name="is_active"
                               value="1"
                               {{ old('is_active', true) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="is_active">Active</label>
                    </div>
                    @error('is_active')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-save"></i> Create Logo
                </button>
                <a href="{{ route('admin.rental-software-company-logos.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop

@section('js')
    <script>
        $(document).ready(function() {
            $('.custom-file-input').on('change', function() {
                var fileName = $(this).val().split('\\').pop();
                $(this).next('.custom-file-label').html(fileName || 'Choose file');
            });
        });
    </script>
@stop
