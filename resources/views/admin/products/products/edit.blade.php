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

                <hr>
                <h5 class="mb-3">Dimensions & Weight</h5>
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="height">Height</label>
                            <input type="number"
                                   class="form-control @error('height') is-invalid @enderror"
                                   id="height"
                                   name="height"
                                   value="{{ old('height', $product->height) }}"
                                   placeholder="0.00"
                                   step="0.01"
                                   min="0">
                            @error('height')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="width">Width</label>
                            <input type="number"
                                   class="form-control @error('width') is-invalid @enderror"
                                   id="width"
                                   name="width"
                                   value="{{ old('width', $product->width) }}"
                                   placeholder="0.00"
                                   step="0.01"
                                   min="0">
                            @error('width')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="length">Length</label>
                            <input type="number"
                                   class="form-control @error('length') is-invalid @enderror"
                                   id="length"
                                   name="length"
                                   value="{{ old('length', $product->length) }}"
                                   placeholder="0.00"
                                   step="0.01"
                                   min="0">
                            @error('length')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="linear_unit_id">Linear Unit</label>
                            <select class="form-control @error('linear_unit_id') is-invalid @enderror"
                                    id="linear_unit_id"
                                    name="linear_unit_id">
                                <option value="">-- Select Unit --</option>
                                @foreach($linearUnits as $unit)
                                    <option value="{{ $unit->id }}" {{ old('linear_unit_id', $product->linear_unit_id) == $unit->id ? 'selected' : '' }}>
                                        {{ $unit->name }} ({{ $unit->code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('linear_unit_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="replacement_price">Replacement Price</label>
                            <input type="number"
                                   class="form-control @error('replacement_price') is-invalid @enderror"
                                   id="replacement_price"
                                   name="replacement_price"
                                   value="{{ old('replacement_price', $product->replacement_price) }}"
                                   placeholder="0.00"
                                   step="0.01"
                                   min="0">
                            @error('replacement_price')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="weight">Weight</label>
                            <input type="number"
                                   class="form-control @error('weight') is-invalid @enderror"
                                   id="weight"
                                   name="weight"
                                   value="{{ old('weight', $product->weight) }}"
                                   placeholder="0.00"
                                   step="0.01"
                                   min="0">
                            @error('weight')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="weight_unit_id">Weight Unit</label>
                            <select class="form-control @error('weight_unit_id') is-invalid @enderror"
                                    id="weight_unit_id"
                                    name="weight_unit_id">
                                <option value="">-- Select Unit --</option>
                                @foreach($weightUnits as $unit)
                                    <option value="{{ $unit->id }}" {{ old('weight_unit_id', $product->weight_unit_id) == $unit->id ? 'selected' : '' }}>
                                        {{ $unit->name }} ({{ $unit->code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('weight_unit_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <hr>
                <h5 class="mb-3">Additional Information</h5>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="country_of_origin">Country of Origin</label>
                            <input type="text"
                                   class="form-control @error('country_of_origin') is-invalid @enderror"
                                   id="country_of_origin"
                                   name="country_of_origin"
                                   value="{{ old('country_of_origin', $product->country_of_origin) }}"
                                   placeholder="e.g., United States"
                                   maxlength="100">
                            @error('country_of_origin')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="iso_code_2">ISO Code 2</label>
                            <input type="text"
                                   class="form-control @error('iso_code_2') is-invalid @enderror"
                                   id="iso_code_2"
                                   name="iso_code_2"
                                   value="{{ old('iso_code_2', $product->iso_code_2) }}"
                                   placeholder="e.g., US"
                                   maxlength="2"
                                   style="text-transform: uppercase;">
                            @error('iso_code_2')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="iso_code_3">ISO Code 3</label>
                            <input type="text"
                                   class="form-control @error('iso_code_3') is-invalid @enderror"
                                   id="iso_code_3"
                                   name="iso_code_3"
                                   value="{{ old('iso_code_3', $product->iso_code_3) }}"
                                   placeholder="e.g., USA"
                                   maxlength="3"
                                   style="text-transform: uppercase;">
                            @error('iso_code_3')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="hsn_code">HSN Code</label>
                            <input type="text"
                                   class="form-control @error('hsn_code') is-invalid @enderror"
                                   id="hsn_code"
                                   name="hsn_code"
                                   value="{{ old('hsn_code', $product->hsn_code) }}"
                                   placeholder="e.g., 8429"
                                   maxlength="20">
                            @error('hsn_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
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
            var subCategorySelect = $('#sub_category_id');
            // Store the original sub_category_id for edit mode (from old input or product)
            var originalSubCategoryId = '{{ old('sub_category_id', $product->sub_category_id) }}';
            
            // Function to load subcategories
            function loadSubCategories(categoryId, preserveSelection) {
                subCategorySelect.html('<option value="">-- Loading... --</option>');

                if (categoryId) {
                    $.ajax({
                        url: '/admin/ajax/categories/' + categoryId + '/subcategories',
                        type: 'GET',
                        dataType: 'json',
                        success: function(data) {
                            subCategorySelect.html('<option value="">-- Select Sub-Category --</option>');
                            $.each(data, function(key, subCategory) {
                                var selected = (preserveSelection && subCategory.id == originalSubCategoryId) ? 'selected' : '';
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
            }

            // Load subcategories when category is selected
            $('#category_id').on('change', function() {
                var categoryId = $(this).val();
                loadSubCategories(categoryId, true);
            });
            
            // On page load, if category changed (from old input), reload sub-categories
            var selectedCategoryId = $('#category_id').val();
            var originalCategoryId = '{{ $product->category_id }}';
            // If category was changed in the form submission, reload sub-categories
            if (selectedCategoryId && selectedCategoryId != originalCategoryId) {
                loadSubCategories(selectedCategoryId, true);
            }
        });
    </script>
@stop

