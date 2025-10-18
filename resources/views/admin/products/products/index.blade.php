@extends('adminlte::page')

@section('title', 'Products')

@section('content_header')
    <h1>Products Management</h1>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-check"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-ban"></i> {{ session('error') }}
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">All Products</h3>
            <div class="card-tools">
                <a href="{{ route('admin.products.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add New Product
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="productsTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Brand</th>
                        <th>Model</th>
                        <th>Category</th>
                        <th>Sub-Category</th>
                        <th>PSM Code</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be loaded via AJAX -->
                </tbody>
            </table>
        </div>
    </div>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            initResponsiveDataTable('productsTable', {
                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "{{ route('admin.products.data') }}",
                    "type": "GET",
                    "error": function(xhr, error, thrown) {
                        console.error('DataTables AJAX error:', error, thrown);
                        alert('Error loading products data. Please refresh the page.');
                    }
                },
                "columns": [
                    { "data": "id", "name": "id" },
                    {
                        "data": "brand",
                        "name": "brand",
                        "render": function(data, type, row) {
                            return '<span class="badge badge-success">' + data + '</span>';
                        }
                    },
                    {
                        "data": "model",
                        "name": "model",
                        "render": function(data, type, row) {
                            return '<strong>' + data + '</strong>';
                        }
                    },
                    {
                        "data": "category",
                        "name": "category",
                        "render": function(data, type, row) {
                            return '<span class="badge badge-primary">' + data + '</span>';
                        }
                    },
                    {
                        "data": "sub_category",
                        "name": "sub_category",
                        "render": function(data, type, row) {
                            if (data === '—') {
                                return '<span class="text-muted">—</span>';
                            }
                            return '<span class="badge badge-info">' + data + '</span>';
                        }
                    },
                    { "data": "psm_code", "name": "psm_code" },
                    { "data": "created_at", "name": "created_at" },
                    {
                        "data": "actions",
                        "name": "actions",
                        "orderable": false,
                        "searchable": false
                    }
                ],
                "columnDefs": [
                    { "orderable": false, "targets": [7] }, // Actions column
                    { "responsivePriority": 1, "targets": 1 }, // Brand
                    { "responsivePriority": 2, "targets": 7 }, // Actions
                    { "responsivePriority": 3, "targets": [2, 3] } // Model and Category
                ],
                "order": [[0, "desc"]], // Sort by ID descending by default
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]]
            });
        });
    </script>
@stop

