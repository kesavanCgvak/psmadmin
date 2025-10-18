@extends('adminlte::page')

@section('title', 'Create Region')

@section('content_header')
    <h1>Create New Region</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-primary">
        <div class="card-header">
            <h3 class="card-title">Region Details</h3>
        </div>
        <form action="{{ route('regions.store') }}" method="POST">
            @csrf
            <div class="card-body">
                <div class="form-group">
                    <label for="name">Region Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           name="name"
                           value="{{ old('name') }}"
                           placeholder="Enter region name"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create Region
                </button>
                <a href="{{ route('regions.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop

