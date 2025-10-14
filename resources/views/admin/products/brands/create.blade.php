@extends('adminlte::page')

@section('title', 'Create Brand')

@section('content_header')
    <h1>Create New Brand</h1>
@stop

@section('content')
    <div class="card card-success">
        <div class="card-header">
            <h3 class="card-title">Brand Details</h3>
        </div>
        <form action="{{ route('brands.store') }}" method="POST">
            @csrf
            <div class="card-body">
                <div class="form-group">
                    <label for="name">Brand Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           name="name"
                           value="{{ old('name') }}"
                           placeholder="e.g., Caterpillar, JCB, Komatsu, Volvo"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <small class="form-text text-muted">Enter a unique brand name</small>
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Create Brand
                </button>
                <a href="{{ route('brands.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop

