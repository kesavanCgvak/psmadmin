@extends('adminlte::page')

@section('title', 'Create Sub-Category')

@section('content_header')
    <h1>Create New Sub-Category</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-info">
        <div class="card-header">
            <h3 class="card-title">Sub-Category Details</h3>
        </div>
        <form action="{{ route('subcategories.store') }}" method="POST">
            @csrf
            <div class="card-body">
                <div class="form-group">
                    <label for="category_id">Parent Category <span class="text-danger">*</span></label>
                    <select class="form-control @error('category_id') is-invalid @enderror"
                            id="category_id"
                            name="category_id"
                            required>
                        <option value="">-- Select Category --</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="name">Sub-Category Name <span class="text-danger">*</span></label>
                    <input type="text"
                           class="form-control @error('name') is-invalid @enderror"
                           id="name"
                           name="name"
                           value="{{ old('name') }}"
                           placeholder="e.g., Mini Excavators, Wheel Loaders"
                           required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-info">
                    <i class="fas fa-save"></i> Create Sub-Category
                </button>
                <a href="{{ route('subcategories.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop

