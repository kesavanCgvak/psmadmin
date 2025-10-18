@extends('adminlte::page')

@section('title', 'Edit Company')

@section('content_header')
    <h1>Edit Company</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <form action="{{ route('admin.companies.update', $company) }}" method="POST">
        @csrf
        @method('PUT')

        <!-- Basic Information -->
        <div class="card card-warning">
            <div class="card-header">
                <h3 class="card-title">Basic Information</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="name">Company Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           name="name"
                           value="{{ old('name', $company->name) }}"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea class="form-control @error('description') is-invalid @enderror"
                              id="description"
                              name="description"
                              rows="3">{{ old('description', $company->description) }}</textarea>
                    @error('description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Location Information -->
        <div class="card card-info">
            <div class="card-header">
                <h3 class="card-title">Location Information</h3>
            </div>
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
                                    <option value="{{ $region->id }}" {{ old('region_id', $company->region_id) == $region->id ? 'selected' : '' }}>
                                        {{ $region->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('region_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="country_id">Country</label>
                            <select class="form-control @error('country_id') is-invalid @enderror"
                                    id="country_id"
                                    name="country_id">
                                <option value="">-- Select Country --</option>
                                @foreach($countries as $country)
                                    <option value="{{ $country->id }}" {{ old('country_id', $company->country_id) == $country->id ? 'selected' : '' }}>
                                        {{ $country->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('country_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="state_id">State/Province</label>
                            <select class="form-control @error('state_id') is-invalid @enderror"
                                    id="state_id"
                                    name="state_id">
                                <option value="">-- Select State/Province --</option>
                                @foreach($states as $state)
                                    <option value="{{ $state->id }}" {{ old('state_id', $company->state_id) == $state->id ? 'selected' : '' }}>
                                        {{ $state->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('state_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="city_id">City</label>
                            <select class="form-control @error('city_id') is-invalid @enderror"
                                    id="city_id"
                                    name="city_id">
                                <option value="">-- Select City --</option>
                                @foreach($cities as $city)
                                    <option value="{{ $city->id }}" {{ old('city_id', $company->city_id) == $city->id ? 'selected' : '' }}>
                                        {{ $city->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('city_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="address_line_1">Address Line 1</label>
                            <input type="text"
                                   class="form-control @error('address_line_1') is-invalid @enderror"
                                   id="address_line_1"
                                   name="address_line_1"
                                   value="{{ old('address_line_1', $company->address_line_1) }}">
                            @error('address_line_1')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="address_line_2">Address Line 2</label>
                            <input type="text"
                                   class="form-control @error('address_line_2') is-invalid @enderror"
                                   id="address_line_2"
                                   name="address_line_2"
                                   value="{{ old('address_line_2', $company->address_line_2) }}">
                            @error('address_line_2')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="postal_code">Postal Code</label>
                            <input type="text"
                                   class="form-control @error('postal_code') is-invalid @enderror"
                                   id="postal_code"
                                   name="postal_code"
                                   value="{{ old('postal_code', $company->postal_code) }}">
                            @error('postal_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="latitude">Latitude</label>
                            <input type="number"
                                   step="0.000001"
                                   class="form-control @error('latitude') is-invalid @enderror"
                                   id="latitude"
                                   name="latitude"
                                   value="{{ old('latitude', $company->latitude) }}"
                                   min="-90" max="90">
                            @error('latitude')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="longitude">Longitude</label>
                            <input type="number"
                                   step="0.000001"
                                   class="form-control @error('longitude') is-invalid @enderror"
                                   id="longitude"
                                   name="longitude"
                                   value="{{ old('longitude', $company->longitude) }}"
                                   min="-180" max="180">
                            @error('longitude')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preferences -->
        <div class="card card-success">
            <div class="card-header">
                <h3 class="card-title">Preferences</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="currency_id">Currency</label>
                            <select class="form-control @error('currency_id') is-invalid @enderror"
                                    id="currency_id"
                                    name="currency_id">
                                <option value="">-- Select Currency --</option>
                                @foreach($currencies as $currency)
                                    <option value="{{ $currency->id }}" {{ old('currency_id', $company->currency_id) == $currency->id ? 'selected' : '' }}>
                                        {{ $currency->code }} - {{ $currency->name }} ({{ $currency->symbol }})
                                    </option>
                                @endforeach
                            </select>
                            @error('currency_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="rental_software_id">Rental Software</label>
                            <select class="form-control @error('rental_software_id') is-invalid @enderror"
                                    id="rental_software_id"
                                    name="rental_software_id">
                                <option value="">-- Select Rental Software --</option>
                                @foreach($rentalSoftwares as $software)
                                    <option value="{{ $software->id }}" {{ old('rental_software_id', $company->rental_software_id) == $software->id ? 'selected' : '' }}>
                                        {{ $software->name }}
                                        @if($software->version)
                                            (v{{ $software->version }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('rental_software_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="date_format">Date Format</label>
                            <select class="form-control @error('date_format') is-invalid @enderror"
                                    id="date_format"
                                    name="date_format">
                                <option value="">-- Select Format --</option>
                                <option value="MM/DD/YYYY" {{ old('date_format', $company->date_format) == 'MM/DD/YYYY' ? 'selected' : '' }}>MM/DD/YYYY</option>
                                <option value="DD/MM/YYYY" {{ old('date_format', $company->date_format) == 'DD/MM/YYYY' ? 'selected' : '' }}>DD/MM/YYYY</option>
                                <option value="YYYY-MM-DD" {{ old('date_format', $company->date_format) == 'YYYY-MM-DD' ? 'selected' : '' }}>YYYY-MM-DD</option>
                            </select>
                            @error('date_format')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="pricing_scheme">Pricing Scheme</label>
                            <select class="form-control @error('pricing_scheme') is-invalid @enderror"
                                    id="pricing_scheme"
                                    name="pricing_scheme">
                                <option value="">-- Select Scheme --</option>
                                <option value="Day Price" {{ old('pricing_scheme', $company->pricing_scheme) == 'Day Price' ? 'selected' : '' }}>Day Price</option>
                                <option value="Week Price" {{ old('pricing_scheme', $company->pricing_scheme) == 'Week Price' ? 'selected' : '' }}>Week Price</option>
                                <option value="Month Price" {{ old('pricing_scheme', $company->pricing_scheme) == 'Month Price' ? 'selected' : '' }}>Month Price</option>
                                <option value="Custom" {{ old('pricing_scheme', $company->pricing_scheme) == 'Custom' ? 'selected' : '' }}>Custom</option>
                            </select>
                            @error('pricing_scheme')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="card-footer">
            <button type="submit" class="btn btn-warning">
                <i class="fas fa-save"></i> Update Company
            </button>
            <a href="{{ route('admin.companies.index') }}" class="btn btn-default">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </form>
@stop

@section('js')
<script>
$(document).ready(function() {
    // Store initial values from the company being edited
    const initialRegionId = "{{ old('region_id', $company->region_id) }}";
    const initialCountryId = "{{ old('country_id', $company->country_id) }}";
    const initialStateId = "{{ old('state_id', $company->state_id) }}";
    const initialCityId = "{{ old('city_id', $company->city_id) }}";

    // Load initial cascading data based on current company values
    if (initialRegionId && initialCountryId) {
        loadCountries(initialRegionId, initialCountryId);
    } else if (initialRegionId) {
        loadCountries(initialRegionId);
    }

    if (initialCountryId && initialStateId) {
        loadStates(initialCountryId, initialStateId);
    } else if (initialCountryId) {
        loadStates(initialCountryId);
    }

    if (initialStateId && initialCityId) {
        loadCities(initialStateId, initialCityId);
    } else if (initialStateId) {
        loadCities(initialStateId);
    }

    // Region change handler
    $('#region_id').on('change', function() {
        const regionId = $(this).val();

        // Reset and disable dependent dropdowns
        resetDropdown($('#country_id'), 'Select Country');
        resetDropdown($('#state_id'), 'Select State/Province');
        resetDropdown($('#city_id'), 'Select City');
        clearCoordinates();

        if (regionId) {
            $('#country_id').prop('disabled', false);
            loadCountries(regionId);
        } else {
            $('#country_id').prop('disabled', true);
            $('#state_id').prop('disabled', true);
            $('#city_id').prop('disabled', true);
        }
    });

    // Country change handler
    $('#country_id').on('change', function() {
        const countryId = $(this).val();

        // Reset dependent dropdowns
        resetDropdown($('#state_id'), 'Select State/Province');
        resetDropdown($('#city_id'), 'Select City');
        clearCoordinates();

        if (countryId) {
            $('#state_id').prop('disabled', false);
            loadStates(countryId);
        } else {
            $('#state_id').prop('disabled', true);
            $('#city_id').prop('disabled', true);
        }
    });

    // State change handler
    $('#state_id').on('change', function() {
        const stateId = $(this).val();

        // Reset city dropdown
        resetDropdown($('#city_id'), 'Select City');
        clearCoordinates();

        if (stateId) {
            $('#city_id').prop('disabled', false);
            loadCities(stateId);
        } else {
            $('#city_id').prop('disabled', true);
        }
    });

    // City change handler - auto-fetch coordinates
    $('#city_id').on('change', function() {
        const cityId = $(this).val();

        if (cityId) {
            loadCityCoordinates(cityId);
        } else {
            clearCoordinates();
        }
    });

    // Function to load countries by region
    function loadCountries(regionId, selectedId = null) {
        showLoading($('#country_id'));

        $.ajax({
            url: '/admin/ajax/regions/' + regionId + '/countries',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                populateDropdown($('#country_id'), data, 'Select Country', selectedId);
                $('#country_id').prop('disabled', false);
            },
            error: function() {
                resetDropdown($('#country_id'), 'Error loading countries');
                showNotification('error', 'Failed to load countries');
            }
        });
    }

    // Function to load states by country
    function loadStates(countryId, selectedId = null) {
        showLoading($('#state_id'));

        $.ajax({
            url: '/admin/ajax/countries/' + countryId + '/states',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                populateDropdown($('#state_id'), data, 'Select State/Province', selectedId);
                $('#state_id').prop('disabled', false);
            },
            error: function() {
                resetDropdown($('#state_id'), 'Error loading states');
                showNotification('error', 'Failed to load states/provinces');
            }
        });
    }

    // Function to load cities by state
    function loadCities(stateId, selectedId = null) {
        showLoading($('#city_id'));

        $.ajax({
            url: '/admin/ajax/states/' + stateId + '/cities',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                populateDropdown($('#city_id'), data, 'Select City', selectedId);
                $('#city_id').prop('disabled', false);
            },
            error: function() {
                resetDropdown($('#city_id'), 'Error loading cities');
                showNotification('error', 'Failed to load cities');
            }
        });
    }

    // Function to load city coordinates
    function loadCityCoordinates(cityId) {
        // Show loading state
        const currentLat = $('#latitude').val();
        const currentLng = $('#longitude').val();

        $('#latitude').attr('placeholder', 'Loading...');
        $('#longitude').attr('placeholder', 'Loading...');

        $.ajax({
            url: '/admin/ajax/cities/' + cityId + '/coordinates',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                if (data.latitude && data.longitude) {
                    $('#latitude').val(data.latitude).attr('placeholder', 'Latitude');
                    $('#longitude').val(data.longitude).attr('placeholder', 'Longitude');
                    showNotification('success', 'Coordinates loaded successfully');
                } else {
                    $('#latitude').val(currentLat).attr('placeholder', 'Not available');
                    $('#longitude').val(currentLng).attr('placeholder', 'Not available');
                    showNotification('info', 'No coordinates available for this city');
                }
            },
            error: function() {
                $('#latitude').val(currentLat).attr('placeholder', 'Error loading');
                $('#longitude').val(currentLng).attr('placeholder', 'Error loading');
                showNotification('error', 'Failed to load coordinates');
            }
        });
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

    // Helper function to clear coordinates (keeping existing values)
    function clearCoordinates() {
        // Don't actually clear in edit mode, just reset placeholder
        $('#latitude').attr('placeholder', 'Latitude');
        $('#longitude').attr('placeholder', 'Longitude');
    }

    // Helper function to show notifications
    function showNotification(type, message) {
        // You can implement a toast notification here if desired
        // For now, just console log
        console.log(type.toUpperCase() + ': ' + message);
    }
});
</script>
@stop

