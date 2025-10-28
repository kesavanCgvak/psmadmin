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
                <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>
                </button>
                <a href="{{ route('admin.products.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add New Product
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="productsTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" title="Select All">
                        </th>
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
            var productsTable = initResponsiveDataTable('productsTable', {
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
                    {
                        "data": "checkbox",
                        "name": "checkbox",
                        "orderable": false,
                        "searchable": false,
                        "render": function(data, type, row) {
                            return '<input type="checkbox" class="row-checkbox" name="product_ids[]" value="' + row.id + '" data-name="' + row.model + '">';
                        }
                    },
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
                    { "orderable": false, "targets": [0, 8] }, // Checkbox and Actions columns
                    { "searchable": false, "targets": [0, 8] }, // Checkbox and Actions columns
                    { "responsivePriority": 1, "targets": 3 }, // Brand
                    { "responsivePriority": 2, "targets": 8 }, // Actions
                    { "responsivePriority": 3, "targets": [3, 4] } // Model and Category
                ],
                "order": [[1, "desc"]], // Sort by ID descending by default
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]]
            });

            // Bulk delete functionality for server-side DataTable
            $('#selectAll').on('change', function() {
                $('.row-checkbox').prop('checked', $(this).prop('checked'));
                updateBulkDeleteButton();
            });

            $(document).on('change', '.row-checkbox', function() {
                updateBulkDeleteButton();
                var totalCheckboxes = $('.row-checkbox').length;
                var checkedCheckboxes = $('.row-checkbox:checked').length;
                $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0);
            });

            function updateBulkDeleteButton() {
                var checked = $('.row-checkbox:checked');
                if (checked.length > 0) {
                    $('#bulkDeleteBtn').show().html('<i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected (' + checked.length + ')</span><span class="d-lg-none">Delete</span>');
                } else {
                    $('#bulkDeleteBtn').hide();
                }
            }

            $('#bulkDeleteBtn').on('click', function() {
                var selectedIds = [];
                var selectedNames = [];
                $('.row-checkbox:checked').each(function() {
                    selectedIds.push($(this).val());
                    selectedNames.push($(this).data('name'));
                });

                if (selectedIds.length === 0) {
                    alert('Please select at least one product to delete.');
                    return;
                }

                var message = 'Are you sure you want to delete ' + selectedIds.length + ' product/products?\n\n';
                message += 'Products to be deleted:\n';
                selectedNames.forEach(function(name, index) {
                    message += (index + 1) + '. ' + name + '\n';
                });
                message += '\nThis action cannot be undone!';

                if (confirm(message)) {
                    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

                    $.ajax({
                        url: '{{ route("admin.products.bulk-delete") }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            product_ids: selectedIds
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Successfully deleted ' + response.deleted_count + ' product/products.');
                                // Refresh DataTable
                                $('#productsTable').DataTable().ajax.reload();
                                // Reset select all checkbox
                                $('#selectAll').prop('checked', false);
                            } else {
                                alert('Error: ' + (response.message || 'Failed to delete products.'));
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred while deleting products.';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            alert(message);
                        },
                        complete: function() {
                            $('#bulkDeleteBtn').prop('disabled', false).html('<i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>');
                        }
                    });
                }
            });
        });
    </script>
@stop

