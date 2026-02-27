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
                <button type="button" id="bulkVerifyBtn" class="btn btn-success btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-check-circle"></i> <span class="d-none d-lg-inline">Verify Selected</span><span class="d-lg-none">Verify</span>
                </button>
                <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>
                </button>
                <a href="{{ route('admin.products.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add New Product
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="filterUnverified" name="filter_unverified">
                    <label class="form-check-label" for="filterUnverified">
                        Show only unverified products
                    </label>
                </div>
            </div>
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
                        <th>Verified Status</th>
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

    <!-- Merge Product Modal -->
    <div class="modal fade" id="mergeProductModal" tabindex="-1" role="dialog" aria-labelledby="mergeProductModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mergeProductModalLabel">Merge/Replace Product</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Product to merge:</strong> <span id="mergeProductName"></span> (<span id="mergeProductPsmCode"></span>)
                        <br><small>This product will be merged into the correct product you select below. All references will be updated.</small>
                    </div>
                    <div class="form-group">
                        <label for="productSearch">Search for correct product:</label>
                        <input type="text" class="form-control" id="productSearch" placeholder="Type product name or PSM code...">
                        <input type="hidden" id="wrongProductId" value="">
                    </div>
                    <div id="productSearchResults" style="max-height: 300px; overflow-y: auto; margin-top: 10px;">
                        <!-- Search results will appear here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmMergeBtn" disabled>Confirm Merge</button>
                </div>
            </div>
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
            // Restore filter states from localStorage BEFORE initializing DataTables
            var savedUnverified = localStorage.getItem('products_filter_unverified');
            if (savedUnverified === '1') {
                $('#filterUnverified').prop('checked', true);
            }

            var productsTable = initResponsiveDataTable('productsTable', {
                "processing": true,
                "serverSide": true,
                "stateSave": false, // Disable state saving to show all records by default
                "stateDuration": -1, // Don't save state
                "ajax": {
                    "url": "{{ route('admin.products.data') }}",
                    "type": "GET",
                    "data": function(d) {
                        d.unverified_only = $('#filterUnverified').is(':checked') ? '1' : '0';
                    },
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
                            return '<input type="checkbox" class="row-checkbox" name="product_ids[]" value="' + row.id + '" data-name="' + row.model + '" data-verified="' + (row.is_verified == 1 ? '1' : '0') + '">';
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
                    {
                        "data": "is_verified",
                        "name": "is_verified",
                        "render": function(data, type, row) {
                            if (data == 1) {
                                return '<span class="badge badge-success" title="Verified"><i class="fas fa-check-circle"></i> Verified</span>';
                            } else {
                                return '<span class="badge badge-warning" title="Unverified"><i class="fas fa-times-circle"></i> Unverified</span>';
                            }
                        }
                    },
                    { "data": "created_at", "name": "created_at" },
                    {
                        "data": "actions",
                        "name": "actions",
                        "orderable": false,
                        "searchable": false
                    }
                ],
                "columnDefs": [
                    { "orderable": false, "targets": [0, 9] }, // Checkbox and Actions columns
                    { "searchable": false, "targets": [0, 9] }, // Checkbox and Actions columns
                    { "responsivePriority": 1, "targets": 3 }, // Brand
                    { "responsivePriority": 2, "targets": 9 }, // Actions
                    { "responsivePriority": 3, "targets": [3, 4] } // Model and Category
                ],
                "order": [[1, "desc"]], // Sort by ID descending by default
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                "stateSave": false, // Disable state saving to prevent search filter persistence
                "stateDuration": -1, // Don't save state
                "drawCallback": function(settings) {
                    // Update bulk buttons after table redraw
                    updateBulkButtons();
                    // Reset select all checkbox
                    var totalCheckboxes = $('.row-checkbox').length;
                    var checkedCheckboxes = $('.row-checkbox:checked').length;
                    $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0);
                }
            });

            // Clear any saved state from localStorage for this table (DataTables default)
            localStorage.removeItem('DataTables_productsTable');

            // Restore all saved filter states from localStorage after table is initialized
            setTimeout(function() {
                var savedSearch = localStorage.getItem('products_filter_search');
                var savedPageLength = localStorage.getItem('products_filter_pageLength');
                var savedOrder = localStorage.getItem('products_filter_order');
                var savedPage = localStorage.getItem('products_filter_page');

                var needsRedraw = false;

                // Restore page length (doesn't trigger draw automatically)
                if (savedPageLength) {
                    var pageLen = parseInt(savedPageLength);
                    if (productsTable.page.len() !== pageLen) {
                        productsTable.page.len(pageLen);
                        needsRedraw = true;
                    }
                }

                // Restore sorting (doesn't trigger draw automatically for server-side)
                if (savedOrder) {
                    try {
                        var orderArray = JSON.parse(savedOrder);
                        if (Array.isArray(orderArray) && orderArray.length > 0) {
                            productsTable.order(orderArray);
                            needsRedraw = true;
                        }
                    } catch (e) {
                        console.error('Error parsing saved order:', e);
                    }
                }

                // Restore search filter (doesn't trigger draw automatically for server-side)
                if (savedSearch && productsTable.search() !== savedSearch) {
                    productsTable.search(savedSearch);
                    needsRedraw = true;
                }

                // Restore pagination and trigger single draw with all restored state
                if (needsRedraw || savedPage) {
                    if (savedPage) {
                        productsTable.page(parseInt(savedPage));
                    }
                    productsTable.draw(false); // false = don't reset paging
                }
            }, 200);

            // Filter unverified products - save state to localStorage
            $('#filterUnverified').on('change', function() {
                var isChecked = $(this).is(':checked') ? '1' : '0';
                localStorage.setItem('products_filter_unverified', isChecked);
                // Reset to first page when filter changes
                localStorage.setItem('products_filter_page', '0');
                productsTable.ajax.reload();
            });

            // Save search filter to localStorage
            productsTable.on('search.dt', function() {
                var searchValue = productsTable.search();
                if (searchValue) {
                    localStorage.setItem('products_filter_search', searchValue);
                } else {
                    localStorage.removeItem('products_filter_search');
                }
                // Reset to first page when search changes
                localStorage.setItem('products_filter_page', '0');
            });

            // Save pagination state to localStorage
            productsTable.on('page.dt', function() {
                localStorage.setItem('products_filter_page', productsTable.page());
            });

            productsTable.on('length.dt', function(e, settings, len) {
                localStorage.setItem('products_filter_pageLength', len);
                localStorage.setItem('products_filter_page', '0'); // Reset to first page when page length changes
            });

            // Save sorting state to localStorage
            productsTable.on('order.dt', function() {
                var order = productsTable.order();
                localStorage.setItem('products_filter_order', JSON.stringify(order));
            });

            // Bulk delete functionality for server-side DataTable
            $('#selectAll').on('change', function() {
                $('.row-checkbox').prop('checked', $(this).prop('checked'));
                updateBulkButtons();
            });

            $(document).on('change', '.row-checkbox', function() {
                updateBulkButtons();
                var totalCheckboxes = $('.row-checkbox').length;
                var checkedCheckboxes = $('.row-checkbox:checked').length;
                $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes && totalCheckboxes > 0);
            });

            function updateBulkButtons() {
                var checked = $('.row-checkbox:checked');
                var checkedCount = checked.length;

                if (checkedCount > 0) {
                    // Show delete button with count
                    $('#bulkDeleteBtn').show().html('<i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected (' + checkedCount + ')</span><span class="d-lg-none">Delete</span>');

                    // Check if any selected products are unverified
                    var hasUnverified = false;
                    checked.each(function() {
                        if ($(this).data('verified') == '0') {
                            hasUnverified = true;
                            return false; // break loop
                        }
                    });

                    // Only show verify button if there are unverified products selected
                    if (hasUnverified) {
                        $('#bulkVerifyBtn').show().html('<i class="fas fa-check-circle"></i> <span class="d-none d-lg-inline">Verify Selected (' + checkedCount + ')</span><span class="d-lg-none">Verify</span>');
                    } else {
                        $('#bulkVerifyBtn').hide();
                    }
                } else {
                    // Hide both buttons when nothing is selected
                    $('#bulkDeleteBtn').hide();
                    $('#bulkVerifyBtn').hide();
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
                                productsTable.ajax.reload(function() {
                                    // Reset select all checkbox and hide buttons after reload
                                    $('#selectAll').prop('checked', false);
                                    updateBulkButtons();
                                });
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
                            $('#bulkDeleteBtn').prop('disabled', false);
                            updateBulkButtons(); // Update button text with current count
                        }
                    });
                }
            });

            // Bulk verify functionality
            $('#bulkVerifyBtn').on('click', function() {
                var selectedIds = [];
                var selectedNames = [];
                $('.row-checkbox:checked').each(function() {
                    selectedIds.push($(this).val());
                    selectedNames.push($(this).data('name'));
                });

                if (selectedIds.length === 0) {
                    alert('Please select at least one product to verify.');
                    return;
                }

                var message = 'Are you sure you want to verify ' + selectedIds.length + ' product/products?';

                if (confirm(message)) {
                    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Verifying...');

                    $.ajax({
                        url: '{{ route("admin.products.bulk-verify") }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            product_ids: selectedIds
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Successfully verified ' + response.updated_count + ' product/products.');
                                // Refresh DataTable
                                productsTable.ajax.reload(function() {
                                    // Reset select all checkbox and hide buttons after reload
                                    $('#selectAll').prop('checked', false);
                                    updateBulkButtons();
                                });
                            } else {
                                alert('Error: ' + (response.message || 'Failed to verify products.'));
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred while verifying products.';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            alert(message);
                        },
                        complete: function() {
                            $('#bulkVerifyBtn').prop('disabled', false);
                            updateBulkButtons(); // Update button text with current count
                        }
                    });
                }
            });

            // Merge product functionality
            var selectedCorrectProductId = null;
            var searchTimeout = null;

            // Reset merge button when modal is shown (safety check)
            $('#mergeProductModal').on('show.bs.modal', function() {
                $('#confirmMergeBtn').prop('disabled', true).html('Confirm Merge');
            });

            $(document).on('click', '.merge-product-btn', function() {
                var productId = $(this).data('product-id');
                var productName = $(this).data('product-name');
                var psmCode = $(this).data('psm-code');

                $('#wrongProductId').val(productId);
                $('#mergeProductName').text(productName);
                $('#mergeProductPsmCode').text(psmCode || 'N/A');
                $('#productSearch').val('');
                $('#productSearchResults').html('');
                selectedCorrectProductId = null;

                // Reset button to initial state
                $('#confirmMergeBtn').prop('disabled', true).html('Confirm Merge');

                $('#mergeProductModal').modal('show');
            });

            // Product search with debounce
            $('#productSearch').on('input', function() {
                clearTimeout(searchTimeout);
                var searchTerm = $(this).val();
                var excludeId = $('#wrongProductId').val();

                if (searchTerm.length < 2) {
                    $('#productSearchResults').html('');
                    return;
                }

                searchTimeout = setTimeout(function() {
                    $.ajax({
                        url: '{{ route("admin.products.search") }}',
                        method: 'GET',
                        data: {
                            search: searchTerm,
                            exclude_id: excludeId
                        },
                        success: function(response) {
                            var html = '';
                            if (response.length === 0) {
                                html = '<div class="alert alert-warning">No products found.</div>';
                            } else {
                                html = '<div class="list-group">';
                                response.forEach(function(product) {
                                    html += '<a href="#" class="list-group-item list-group-item-action product-select-item" data-product-id="' + product.id + '">';
                                    html += '<div class="d-flex w-100 justify-content-between">';
                                    html += '<h6 class="mb-1">' + product.model + '</h6>';
                                    html += '</div>';
                                    html += '<p class="mb-1"><small>PSM Code: ' + product.psm_code + ' | Brand: ' + product.brand + ' | Category: ' + product.category + '</small></p>';
                                    html += '</a>';
                                });
                                html += '</div>';
                            }
                            $('#productSearchResults').html(html);
                        },
                        error: function() {
                            $('#productSearchResults').html('<div class="alert alert-danger">Error searching products.</div>');
                        }
                    });
                }, 300);
            });

            // Select product from search results
            $(document).on('click', '.product-select-item', function(e) {
                e.preventDefault();
                $('.product-select-item').removeClass('active');
                $(this).addClass('active');
                selectedCorrectProductId = $(this).data('product-id');
                $('#confirmMergeBtn').prop('disabled', false);
            });

            // Confirm merge
            $('#confirmMergeBtn').on('click', function() {
                if (!selectedCorrectProductId) {
                    alert('Please select a product to merge into.');
                    return;
                }

                var wrongProductId = $('#wrongProductId').val();
                var productName = $('#mergeProductName').text();

                if (!confirm('Are you sure you want to merge "' + productName + '" into the selected product? This action cannot be undone.')) {
                    return;
                }

                $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Merging...');

                var mergeUrl = '{{ url("admin/products") }}/' + wrongProductId + '/merge';
                $.ajax({
                    url: mergeUrl,
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        correct_product_id: selectedCorrectProductId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Product merged successfully!');
                            $('#mergeProductModal').modal('hide');
                            // Refresh DataTable
                            productsTable.ajax.reload();
                        } else {
                            alert('Error: ' + (response.message || 'Failed to merge products.'));
                            $('#confirmMergeBtn').prop('disabled', false).html('Confirm Merge');
                        }
                    },
                    error: function(xhr) {
                        var message = 'An error occurred while merging products.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        alert(message);
                        $('#confirmMergeBtn').prop('disabled', false).html('Confirm Merge');
                    }
                });
            });
        });
    </script>
@stop

