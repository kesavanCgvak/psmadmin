@extends('adminlte::page')

@section('title', 'Edit Equipment')

@section('content_header')
    <h1>Edit Equipment</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card card-warning">
        <div class="card-header">
            <h3 class="card-title">Equipment Details</h3>
        </div>
        <form action="{{ route('admin.equipment.update', $equipment) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="company_id">Company <span class="text-danger">*</span></label>
                            <select class="form-control @error('company_id') is-invalid @enderror"
                                    id="company_id"
                                    name="company_id"
                                    required>
                                <option value="">-- Select Company --</option>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}" {{ old('company_id', $equipment->company_id) == $company->id ? 'selected' : '' }}>
                                        {{ $company->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('company_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="user_id">User/Owner <span class="text-danger">*</span></label>
                            <select class="form-control @error('user_id') is-invalid @enderror"
                                    id="user_id"
                                    name="user_id"
                                    required>
                                <option value="">-- Select User --</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" {{ old('user_id', $equipment->user_id) == $user->id ? 'selected' : '' }}>
                                        {{ $user->username }}
                                    </option>
                                @endforeach
                            </select>
                            @error('user_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="product_id">Product <span class="text-danger">*</span></label>
                    <select class="form-control @error('product_id') is-invalid @enderror"
                            id="product_id"
                            name="product_id"
                            required>
                        <option value="">-- Select Product --</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}" {{ old('product_id', $equipment->product_id) == $product->id ? 'selected' : '' }}>
                                {{ $product->brand?->name }} {{ $product->model }}
                                @if($product->psm_code)
                                    ({{ $product->psm_code }})
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @error('product_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="quantity">Quantity <span class="text-danger">*</span></label>
                            <input type="number"
                                   class="form-control @error('quantity') is-invalid @enderror"
                                   id="quantity"
                                   name="quantity"
                                   value="{{ old('quantity', $equipment->quantity) }}"
                                   min="1"
                                   required>
                            @error('quantity')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="price">Price per Day <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">$</span>
                                </div>
                                <input type="number"
                                       step="0.01"
                                       class="form-control @error('price') is-invalid @enderror"
                                       id="price"
                                       name="price"
                                       value="{{ old('price', $equipment->price) }}"
                                       min="0"
                                       required>
                                @error('price')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="software_code">Software Code</label>
                    <input type="text"
                           class="form-control @error('software_code') is-invalid @enderror"
                           id="software_code"
                           name="software_code"
                           value="{{ old('software_code', $equipment->software_code) }}">
                    @error('software_code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea class="form-control @error('description') is-invalid @enderror"
                              id="description"
                              name="description"
                              rows="3">{{ old('description', $equipment->description) }}</textarea>
                    @error('description')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-save"></i> Update Equipment
                </button>
                <a href="{{ route('admin.equipment.index') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
@stop

@section('js')
    <script>
        $(document).ready(function() {
            var originalUserId = '{{ old('user_id', $equipment->user_id) }}';

            // Load users when company is selected
            $('#company_id').on('change', function() {
                var companyId = $(this).val();
                var userSelect = $('#user_id');

                userSelect.html('<option value="">-- Loading... --</option>');

                if (companyId) {
                    $.ajax({
                        url: '/admin/ajax/companies/' + companyId + '/users',
                        type: 'GET',
                        dataType: 'json',
                        success: function(data) {
                            userSelect.html('<option value="">-- Select User --</option>');
                            $.each(data, function(key, user) {
                                var selected = (user.id == originalUserId) ? 'selected' : '';
                                userSelect.append('<option value="' + user.id + '" ' + selected + '>' + user.username + '</option>');
                            });
                        },
                        error: function() {
                            userSelect.html('<option value="">-- Error loading users --</option>');
                        }
                    });
                } else {
                    userSelect.html('<option value="">-- Select User --</option>');
                }
            });
        });
    </script>
@stop

