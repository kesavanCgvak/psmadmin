@extends('adminlte::page')

@section('title', 'Create City')

@section('content_header')
    <h1>Create New City</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-warning">
        <div class="card-header">
            <h3 class="card-title">City Details</h3>
        </div>
        <form action="{{ route('cities.store') }}" method="POST">
            @csrf
            <div class="card-body">
                <div class="form-group">
                    <label for="region_id">Region <span class="text-danger">*</span></label>
                    <select class="form-control @error('region_id') is-invalid @enderror"
                            id="region_id"
                            name="region_id"
                            required>
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

                <div class="row">
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

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="state_id">State/Province</label>
                            <select class="form-control @error('state_id') is-invalid @enderror"
                                    id="state_id"
                                    name="state_id">
                                <option value="">-- Select State/Province --</option>
                                @foreach($states as $state)
                                    <option value="{{ $state->id }}" {{ old('state_id') == $state->id ? 'selected' : '' }}>
                                        {{ $state->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('state_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">Select country first to load states</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="name">City Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           name="name"
                           value="{{ old('name') }}"
                           placeholder="Enter city name"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="latitude">Latitude <span class="text-danger">*</span></label>
                            <input type="number"
                                   step="0.0000001"
                                   class="form-control @error('latitude') is-invalid @enderror"
                                   id="latitude"
                                   name="latitude"
                                   value="{{ old('latitude') }}"
                                   placeholder="e.g., 40.7128"
                                   min="-90"
                                   max="90"
                                   required>
                            @error('latitude')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">Required: Range -90 to 90</small>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="longitude">Longitude <span class="text-danger">*</span></label>
                            <input type="number"
                                   step="0.0000001"
                                   class="form-control @error('longitude') is-invalid @enderror"
                                   id="longitude"
                                   name="longitude"
                                   value="{{ old('longitude') }}"
                                   placeholder="e.g., -74.0060"
                                   min="-180"
                                   max="180"
                                   required>
                            @error('longitude')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">Required: Range -180 to 180</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-save"></i> Create City
                </button>
                <a href="{{ route('cities.index') }}" class="btn btn-default">
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
    const initialStateId = "{{ old('state_id') }}";

    // Disable dependent dropdowns initially
    $('#country_id').prop('disabled', true);
    $('#state_id').prop('disabled', true);

    // Enable country dropdown if region is selected (but it's optional)
    if ($('#region_id').val()) {
        $('#country_id').prop('disabled', false);
    }
    // Enable state dropdown if country is selected
    if ($('#country_id').val()) {
        $('#state_id').prop('disabled', false);
    }

    // Load initial data if old values exist (form validation failure)
    if (initialRegionId && initialCountryId) {
        loadCountries(initialRegionId, initialCountryId);
    }
    if (initialCountryId && initialStateId) {
        loadStates(initialCountryId, initialStateId);
    }

    // Region change handler
    $('#region_id').on('change', function() {
        const regionId = $(this).val();

        // Reset dependent dropdowns
        resetDropdown($('#country_id'), 'Select Country');
        resetDropdown($('#state_id'), 'Select State/Province');

        if (regionId) {
            $('#country_id').prop('disabled', false);
            loadCountries(regionId);
        } else {
            $('#country_id').prop('disabled', true);
            $('#state_id').prop('disabled', true);
        }
    });

    // Country change handler
    $('#country_id').on('change', function() {
        const countryId = $(this).val();

        // Reset state dropdown
        resetDropdown($('#state_id'), 'Select State/Province');

        if (countryId) {
            $('#state_id').prop('disabled', false);
            loadStates(countryId);
        } else {
            $('#state_id').prop('disabled', true);
        }
    });

    // Function to load countries by region
    function loadCountries(regionId, selectedId = null) {
        showLoading($('#country_id'));

        $.ajax({
            url: '/ajax/regions/' + regionId + '/countries-for-cities',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                populateCountriesDropdown($('#country_id'), data, 'Select Country', selectedId);
            },
            error: function() {
                resetDropdown($('#country_id'), 'Error loading countries');
                console.error('Failed to load countries');
            }
        });
    }

    // Function to load states by country
    function loadStates(countryId, selectedId = null) {
        showLoading($('#state_id'));

        $.ajax({
            url: '/ajax/countries/' + countryId + '/states',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                populateDropdown($('#state_id'), data, 'Select State/Province', selectedId);
            },
            error: function() {
                resetDropdown($('#state_id'), 'Error loading states');
                console.error('Failed to load states');
            }
        });
    }

    // Helper function to populate countries dropdown with ISO code
    function populateCountriesDropdown($select, data, placeholder, selectedId = null) {
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

    // Helper function to populate dropdown
    function populateDropdown($select, data, placeholder, selectedId = null) {
        $select.html('<option value="">-- ' + placeholder + ' --</option>');

        if (data && data.length > 0) {
            $.each(data, function(key, item) {
                const selected = selectedId && item.id == selectedId ? ' selected' : '';
                $select.append('<option value="' + item.id + '"' + selected + '>' + item.name + '</option>');
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

