@extends('adminlte::page')

@section('title', 'Create Country')

@section('content_header')
    <h1>Create New Country</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-success">
        <div class="card-header">
            <h3 class="card-title">Country Details</h3>
        </div>
        <form action="{{ route('countries.store') }}" method="POST">
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
                </div>

                <div class="form-group">
                    <label for="name">Country Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           name="name"
                           value="{{ old('name') }}"
                           placeholder="Enter country name"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="iso_code">ISO Code (2 letters) <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control @error('iso_code') is-invalid @enderror"
                                   id="iso_code"
                                   name="iso_code"
                                   value="{{ old('iso_code') }}"
                                   placeholder="e.g., US, GB, FR"
                                   maxlength="2"
                                   style="text-transform: uppercase;"
                                   required>
                            @error('iso_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="phone_code">Phone Code</label>
                            <input type="text"
                                   class="form-control @error('phone_code') is-invalid @enderror"
                                   id="phone_code"
                                   name="phone_code"
                                   value="{{ old('phone_code') }}"
                                   placeholder="e.g., +1, +44, +33">
                            @error('phone_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Create Country
                </button>
                <a href="{{ route('countries.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop

