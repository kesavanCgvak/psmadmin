@extends('adminlte::page')

@section('title', 'Edit Product')

@section('content_header')
    <h1>Edit Product</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-warning">
        <div class="card-header">
            <h3 class="card-title">Product Details</h3>
        </div>
        <form action="{{ route('admin.products.update', $product) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="category_id">Category</label>
                            <select class="form-control @error('category_id') is-invalid @enderror"
                                    id="category_id"
                                    name="category_id">
                                <option value="">-- Select Category --</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" {{ old('category_id', $product->category_id) == $category->id ? 'selected' : '' }}>
                                        {{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('category_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="sub_category_id">Sub-Category</label>
                            <select class="form-control @error('sub_category_id') is-invalid @enderror"
                                    id="sub_category_id"
                                    name="sub_category_id">
                                <option value="">-- Select Sub-Category --</option>
                                @foreach($subCategories as $subCategory)
                                    <option value="{{ $subCategory->id }}" {{ old('sub_category_id', $product->sub_category_id) == $subCategory->id ? 'selected' : '' }}>
                                        {{ $subCategory->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('sub_category_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="brand_id">Brand</label>
                            <select class="form-control @error('brand_id') is-invalid @enderror"
                                    id="brand_id"
                                    name="brand_id">
                                <option value="">-- Select Brand --</option>
                                @foreach($brands as $brand)
                                    <option value="{{ $brand->id }}" {{ old('brand_id', $product->brand_id) == $brand->id ? 'selected' : '' }}>
                                        {{ $brand->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('brand_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="model">Model / Product Name<span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control @error('model') is-invalid @enderror"
                                   id="model"
                                   name="model"
                                   value="{{ old('model', $product->model) }}"
                                   required>
                            @error('model')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="psm_code">PSM Code</label>
                    <input type="text"
                           class="form-control @error('psm_code') is-invalid @enderror"
                           id="psm_code"
                           name="psm_code"
                           value="{{ old('psm_code', $product->psm_code) }}"
                           readonly
                           style="background-color: #f8f9fa; cursor: not-allowed;">
                    @error('psm_code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <small class="form-text text-muted">PSM Code cannot be edited</small>
                </div>

                <div class="form-group">
                    <label for="webpage_url">Product Webpage URL</label>
                    <input type="url"
                           class="form-control @error('webpage_url') is-invalid @enderror"
                           id="webpage_url"
                           name="webpage_url"
                           value="{{ old('webpage_url', $product->webpage_url) }}"
                           placeholder="https://example.com/product-page">
                    @error('webpage_url')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <small class="form-text text-muted">Optional. Link to the manufacturer or product detail page.</small>
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-save"></i> Update Product
                </button>
                <a href="{{ route('admin.products.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop

@section('js')
    <script>
        $(document).ready(function() {
            // Store the original sub_category_id for edit mode
            var originalSubCategoryId = '{{ old('sub_category_id', $product->sub_category_id) }}';

            // Load subcategories when category is selected
            $('#category_id').on('change', function() {
                var categoryId = $(this).val();
                var subCategorySelect = $('#sub_category_id');

                subCategorySelect.html('<option value="">-- Loading... --</option>');

                if (categoryId) {
                    $.ajax({
                        url: '/admin/ajax/categories/' + categoryId + '/subcategories',
                        type: 'GET',
                        dataType: 'json',
                        success: function(data) {
                            subCategorySelect.html('<option value="">-- Select Sub-Category --</option>');
                            $.each(data, function(key, subCategory) {
                                var selected = (subCategory.id == originalSubCategoryId) ? 'selected' : '';
                                subCategorySelect.append('<option value="' + subCategory.id + '" ' + selected + '>' + subCategory.name + '</option>');
                            });
                        },
                        error: function() {
                            subCategorySelect.html('<option value="">-- Error loading sub-categories --</option>');
                        }
                    });
                } else {
                    subCategorySelect.html('<option value="">-- Select Sub-Category --</option>');
                }
            });
        });
    </script>
@stop

