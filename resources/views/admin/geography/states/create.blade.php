@extends('adminlte::page')

@section('title', 'Create State/Province')

@section('content_header')
    <h1>Create New State/Province</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-info">
        <div class="card-header">
            <h3 class="card-title">State/Province Details</h3>
        </div>
        <form action="{{ route('states.store') }}" method="POST">
            @csrf
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="region_id">Region</label>
                            <select class="form-control @error('region_id') is-invalid @enderror"
                                    id="region_id"
                                    name="region_id">
                                <option value="">-- Select Region --</option>
                                @foreach($regions as $region)
                                    <option value="{{ $region->id }}" {{ old('region_id') == $region->id ? 'selected' : '' }}>
                                        {{ $region->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('region_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">Select a region to filter countries</small>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="country_id">Country <span class="text-danger">*</span></label>
                            <select class="form-control @error('country_id') is-invalid @enderror"
                                    id="country_id"
                                    name="country_id"
                                    required>
                                <option value="">-- Select Country --</option>
                                @foreach($countries as $country)
                                    <option value="{{ $country->id }}" {{ old('country_id') == $country->id ? 'selected' : '' }}>
                                        {{ $country->name }} ({{ $country->iso_code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('country_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">Select region first to load countries</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="name">State/Province Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           name="name"
                           value="{{ old('name') }}"
                           placeholder="Enter state/province name"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="code">Code</label>
                            <input type="text"
                                   class="form-control @error('code') is-invalid @enderror"
                                   id="code"
                                   name="code"
                                   value="{{ old('code') }}"
                                   placeholder="e.g., CA, TX, ON"
                                   maxlength="10">
                            @error('code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="type">Type <span class="text-danger">*</span></label>
                            <select class="form-control @error('type') is-invalid @enderror"
                                    id="type"
                                    name="type"
                                    required>
                                <option value="">-- Select Type --</option>
                                @foreach($types as $type)
                                    <option value="{{ $type }}" {{ old('type') == $type ? 'selected' : '' }}>
                                        {{ ucfirst(str_replace('_', ' ', $type)) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-save"></i> Create State/Province
                </button>
                <a href="{{ route('states.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop

@section('js')
<script>
$(document).ready(function() {
    // Store initial values for form reload scenarios
    const initialRegionId = "{{ old('region_id') }}";
    const initialCountryId = "{{ old('country_id') }}";

    // Disable country dropdown initially
    $('#country_id').prop('disabled', true);

    // Enable country dropdown if region is selected
    if ($('#region_id').val()) {
        $('#country_id').prop('disabled', false);
    }

    // Load initial data if old values exist (form validation failure)
    if (initialRegionId && initialCountryId) {
        loadCountries(initialRegionId, initialCountryId);
    }

    // Region change handler
    $('#region_id').on('change', function() {
        const regionId = $(this).val();

        // Reset country dropdown
        resetDropdown($('#country_id'), 'Select Country');

        if (regionId) {
            $('#country_id').prop('disabled', false);
            loadCountries(regionId);
        } else {
            $('#country_id').prop('disabled', true);
        }
    });

    // Function to load countries by region
    function loadCountries(regionId, selectedId = null) {
        showLoading($('#country_id'));

        $.ajax({
            url: '/ajax/regions/' + regionId + '/countries-for-states',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                populateDropdown($('#country_id'), data, 'Select Country', selectedId);
            },
            error: function() {
                resetDropdown($('#country_id'), 'Error loading countries');
                console.error('Failed to load countries');
            }
        });
    }

    // Helper function to populate dropdown with ISO code
    function populateDropdown($select, data, placeholder, selectedId = null) {
        $select.html('<option value="">-- ' + placeholder + ' --</option>');

        if (data && data.length > 0) {
            $.each(data, function(key, item) {
                const selected = selectedId && item.id == selectedId ? ' selected' : '';
                const displayName = item.name + (item.iso_code ? ' (' + item.iso_code + ')' : '');
                $select.append('<option value="' + item.id + '"' + selected + '>' + displayName + '</option>');
            });
        } else {
            $select.append('<option value="">No ' + placeholder.toLowerCase() + ' available</option>');
        }
    }

    // Helper function to reset dropdown
    function resetDropdown($select, placeholder) {
        $select.html('<option value="">-- ' + placeholder + ' --</option>');
    }

    // Helper function to show loading state
    function showLoading($select) {
        $select.html('<option value="">-- Loading... --</option>');
    }
});
</script>
@stop

