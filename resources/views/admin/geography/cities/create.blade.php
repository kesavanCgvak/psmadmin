@extends('adminlte::page')

@section('title', 'Create City')

@section('content_header')
    <h1>Create New City</h1>
@stop

@section('content')
    <div class="card card-warning">
        <div class="card-header">
            <h3 class="card-title">City Details</h3>
        </div>
        <form action="{{ route('cities.store') }}" method="POST">
            @csrf
            <div class="card-body">
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
                            <label for="latitude">Latitude</label>
                            <input type="number"
                                   step="0.0000001"
                                   class="form-control @error('latitude') is-invalid @enderror"
                                   id="latitude"
                                   name="latitude"
                                   value="{{ old('latitude') }}"
                                   placeholder="e.g., 40.7128"
                                   min="-90"
                                   max="90">
                            @error('latitude')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="longitude">Longitude</label>
                            <input type="number"
                                   step="0.0000001"
                                   class="form-control @error('longitude') is-invalid @enderror"
                                   id="longitude"
                                   name="longitude"
                                   value="{{ old('longitude') }}"
                                   placeholder="e.g., -74.0060"
                                   min="-180"
                                   max="180">
                            @error('longitude')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
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
            // Load states when country is selected
            $('#country_id').on('change', function() {
                var countryId = $(this).val();
                var stateSelect = $('#state_id');

                stateSelect.html('<option value="">-- Loading... --</option>');

                if (countryId) {
                    $.ajax({
                        url: '/ajax/countries/' + countryId + '/states',
                        type: 'GET',
                        dataType: 'json',
                        success: function(data) {
                            stateSelect.html('<option value="">-- Select State/Province --</option>');
                            $.each(data, function(key, state) {
                                stateSelect.append('<option value="' + state.id + '">' + state.name + '</option>');
                            });
                        },
                        error: function() {
                            stateSelect.html('<option value="">-- Error loading states --</option>');
                        }
                    });
                } else {
                    stateSelect.html('<option value="">-- Select State/Province --</option>');
                }
            });
        });
    </script>
@stop

