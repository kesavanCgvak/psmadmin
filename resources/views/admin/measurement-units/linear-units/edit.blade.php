@extends('adminlte::page')

@section('title', 'Edit Linear Unit')

@section('content_header')
    <h1>Edit Linear Unit</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-warning">
        <div class="card-header">
            <h3 class="card-title">Linear Unit Details</h3>
        </div>
        <form action="{{ route('admin.linear-units.update', $linearUnit) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="name">Name <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control @error('name') is-invalid @enderror"
                                   id="name"
                                   name="name"
                                   value="{{ old('name', $linearUnit->name) }}"
                                   placeholder="e.g., Inch, Foot, Centimeter"
                                   maxlength="50"
                                   required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="code">Code <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control @error('code') is-invalid @enderror"
                                   id="code"
                                   name="code"
                                   value="{{ old('code', $linearUnit->code) }}"
                                   placeholder="e.g., in, ft, cm"
                                   maxlength="10"
                                   required>
                            @error('code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="form-text text-muted">Unique code (e.g., in, ft, cm)</small>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="system">System <span class="text-danger">*</span></label>
                            <select class="form-control @error('system') is-invalid @enderror" id="system" name="system" required>
                                <option value="">Select System</option>
                                <option value="imperial" {{ old('system', $linearUnit->system) == 'imperial' ? 'selected' : '' }}>Imperial</option>
                                <option value="metric" {{ old('system', $linearUnit->system) == 'metric' ? 'selected' : '' }}>Metric</option>
                            </select>
                            @error('system')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="is_active">Active Status</label>
                            <div class="custom-control custom-switch mt-2">
                                <input type="checkbox"
                                       class="custom-control-input"
                                       id="is_active"
                                       name="is_active"
                                       value="1"
                                       {{ old('is_active', $linearUnit->is_active) ? 'checked' : '' }}>
                                <label class="custom-control-label" for="is_active">Active</label>
                            </div>
                            @error('is_active')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-save"></i> Update Linear Unit
                </button>
                <a href="{{ route('admin.linear-units.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop
