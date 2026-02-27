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
                <a href="{{ route('admin.products.create') }}" class="btn btn-warning btn-sm">
                    <i class="fas fa-plus"></i> <span class="d-none d-sm-inline">Add New Product</span><span class="d-sm-none">Add</span>
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped products-table">
                    <thead>
                        <tr>
                            <th>
                                <a href="{{ request()->fullUrlWithQuery(['sort' => 'id', 'order' => request('order') === 'asc' ? 'desc' : 'asc']) }}"
                                   class="text-decoration-none text-dark">
                                    ID
                                    @if(request('sort') === 'id')
                                        <i class="fas fa-sort-{{ request('order') === 'asc' ? 'up' : 'down' }}"></i>
                                    @else
                                        <i class="fas fa-sort text-muted"></i>
                                    @endif
                                </a>
                            </th>
                            <th>Brand</th>
                            <th>
                                <a href="{{ request()->fullUrlWithQuery(['sort' => 'model', 'order' => request('order') === 'asc' ? 'desc' : 'asc']) }}"
                                   class="text-decoration-none text-dark">
                                    Model
                                    @if(request('sort') === 'model')
                                        <i class="fas fa-sort-{{ request('order') === 'asc' ? 'up' : 'down' }}"></i>
                                    @else
                                        <i class="fas fa-sort text-muted"></i>
                                    @endif
                                </a>
                            </th>
                            <th>Category</th>
                            <th>Sub-Category</th>
                            <th>
                                <a href="{{ request()->fullUrlWithQuery(['sort' => 'psm_code', 'order' => request('order') === 'asc' ? 'desc' : 'asc']) }}"
                                   class="text-decoration-none text-dark">
                                    PSM Code
                                    @if(request('sort') === 'psm_code')
                                        <i class="fas fa-sort-{{ request('order') === 'asc' ? 'up' : 'down' }}"></i>
                                    @else
                                        <i class="fas fa-sort text-muted"></i>
                                    @endif
                                </a>
                            </th>
                            <th>
                                <a href="{{ request()->fullUrlWithQuery(['sort' => 'created_at', 'order' => request('order') === 'asc' ? 'desc' : 'asc']) }}"
                                   class="text-decoration-none text-dark">
                                    Created At
                                    @if(request('sort') === 'created_at')
                                        <i class="fas fa-sort-{{ request('order') === 'asc' ? 'up' : 'down' }}"></i>
                                    @else
                                        <i class="fas fa-sort text-muted"></i>
                                    @endif
                                </a>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $product)
                            <tr>
                                <td>{{ $product->id }}</td>
                                <td>
                                    <span class="badge badge-success">{{ $product->brand?->name ?? '—' }}</span>
                                </td>
                                <td><strong>{{ $product->model }}</strong></td>
                                <td>
                                    <span class="badge badge-primary">{{ $product->category?->name ?? '—' }}</span>
                                </td>
                                <td>
                                    @if($product->subCategory)
                                        <span class="badge badge-info">{{ $product->subCategory->name }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $product->psm_code ?? '—' }}</td>
                                <td>{{ $product->created_at?->format('M d, Y') }}</td>
                                <td>
                                    <div class="btn-group">
                                        <a href="{{ route('admin.products.show', $product) }}" class="btn btn-info btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="{{ route('admin.products.edit', $product) }}" class="btn btn-warning btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form action="{{ route('admin.products.destroy', $product) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($products->hasPages())
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted">
                        Showing {{ $products->firstItem() ?? 0 }} to {{ $products->lastItem() ?? 0 }} of {{ $products->total() }} products
                    </div>
                    <div class="pagination-wrapper">
                        {{ $products->appends(request()->query())->links() }}
                    </div>
                </div>
            @else
                <div class="d-flex justify-content-center align-items-center mt-3">
                    <div class="text-muted">
                        Showing {{ $products->firstItem() ?? 0 }} to {{ $products->lastItem() ?? 0 }} of {{ $products->total() }} products
                    </div>
                </div>
            @endif

            @if($products->count() == 0)
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x"></i>
                    <h5>No products found</h5>
                    <p>There are no products to display at the moment.</p>
                    <a href="{{ route('admin.products.create') }}" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add First Product
                    </a>
                </div>
            @endif
        </div>
    </div>
@stop

@section('css')
    @include('partials.responsive-css')
    <style>
        /* ========== BASE TABLE STYLES ========== */
        .products-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .products-table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            padding: 12px 8px;
            text-align: left;
            white-space: nowrap;
            font-size: 0.875rem;
        }

        .products-table tbody tr {
            transition: background-color 0.15s ease-in-out;
        }

        .products-table tbody tr:hover {
            background-color: #f8f9fa !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .products-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .products-table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        .products-table tbody td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
            font-size: 0.875rem;
        }

        /* ========== COLUMN WIDTHS ========== */
        .products-table th:nth-child(1), .products-table td:nth-child(1) { width: 5%; min-width: 50px; } /* ID */
        .products-table th:nth-child(2), .products-table td:nth-child(2) { width: 12%; min-width: 100px; } /* Brand */
        .products-table th:nth-child(3), .products-table td:nth-child(3) { width: 20%; min-width: 150px; } /* Model */
        .products-table th:nth-child(4), .products-table td:nth-child(4) { width: 15%; min-width: 120px; } /* Category */
        .products-table th:nth-child(5), .products-table td:nth-child(5) { width: 15%; min-width: 120px; } /* SubCategory */
        .products-table th:nth-child(6), .products-table td:nth-child(6) { width: 13%; min-width: 100px; } /* PSM Code */
        .products-table th:nth-child(7), .products-table td:nth-child(7) { width: 12%; min-width: 100px; } /* Created At */
        .products-table th:nth-child(8), .products-table td:nth-child(8) { width: 8%; min-width: 120px; text-align: center; } /* Actions */

        /* ========== TEXT HANDLING ========== */
        .products-table td:nth-child(3) { /* Model */
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .products-table td:nth-child(3):hover {
            white-space: normal;
            word-wrap: break-word;
        }

        /* ========== BADGES ========== */
        .badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
            font-weight: 500;
            white-space: nowrap;
            display: inline-block;
        }

        /* ========== ACTION BUTTONS ========== */
        .btn-group {
            display: flex;
            flex-wrap: nowrap;
            gap: 2px;
            justify-content: center;
        }

        .btn-group .btn-sm {
            padding: 0.375rem 0.5rem;
            font-size: 0.875rem;
        }

        .btn-group form {
            display: inline-block;
            margin: 0;
        }

        /* ========== PAGINATION STYLES ========== */
        .pagination {
            margin-bottom: 0;
            justify-content: center;
            flex-wrap: wrap;
            display: flex;
            list-style: none;
            padding: 0;
        }

        .pagination .page-item {
            margin: 0 2px;
            display: inline-block;
        }

        .pagination .page-link {
            color: #495057;
            background-color: #fff;
            border: 1px solid #dee2e6;
            padding: 0.375rem 0.75rem;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.15s ease-in-out;
            text-decoration: none;
            display: block;
            min-width: 40px;
            text-align: center;
            line-height: 1.25;
        }

        /* Fix oversized chevron icons */
        .pagination .page-link i,
        .pagination .page-link .fa,
        .pagination .page-link .fas {
            font-size: 0.75rem !important;
            line-height: 1 !important;
            margin: 0 !important;
            display: inline !important;
        }

        /* Ensure Previous/Next buttons are properly sized */
        .pagination .page-item:first-child .page-link,
        .pagination .page-item:last-child .page-link {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            min-width: auto;
        }

        .pagination .page-link:hover {
            color: #fff;
            background-color: #007bff;
            border-color: #007bff;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,123,255,0.3);
        }

        .pagination .page-item.active .page-link {
            background-color: #007bff;
            border-color: #007bff;
            color: white;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0,123,255,0.3);
        }

        .pagination .page-item.disabled .page-link {
            color: #6c757d;
            background-color: #fff;
            border-color: #dee2e6;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .pagination .page-item.disabled .page-link:hover {
            color: #6c757d;
            background-color: #fff;
            border-color: #dee2e6;
            transform: none;
            box-shadow: none;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* Remove any oversized elements */
        .pagination * {
            max-width: none !important;
            max-height: none !important;
        }

        /* Ensure proper icon sizing in pagination */
        .pagination .fa-chevron-left,
        .pagination .fa-chevron-right,
        .pagination .fa-angle-left,
        .pagination .fa-angle-right {
            font-size: 0.75rem !important;
            line-height: 1 !important;
        }

        /* Override any potential DataTables pagination conflicts */
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            font-size: 0.875rem !important;
            padding: 0.375rem 0.75rem !important;
            margin: 2px !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 4px !important;
            min-width: 40px !important;
            height: auto !important;
            line-height: 1.25 !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button i,
        .dataTables_wrapper .dataTables_paginate .paginate_button .fa,
        .dataTables_wrapper .dataTables_paginate .paginate_button .fas {
            font-size: 0.75rem !important;
            line-height: 1 !important;
            margin: 0 !important;
        }

        /* Ensure no oversized elements in pagination */
        .pagination .page-link,
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            max-width: 60px !important;
            max-height: 40px !important;
            overflow: hidden !important;
        }

        /* Force pagination icon sizing - override AdminLTE */
        .pagination .page-link i.fa,
        .pagination .page-link i.fas,
        .pagination .page-link i.far,
        .pagination .page-link i.fal,
        .pagination .page-link i.fab {
            font-size: 0.75rem !important;
            width: auto !important;
            height: auto !important;
            line-height: 1 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* Override any AdminLTE pagination styles */
        .content-wrapper .pagination .page-link,
        .main-content .pagination .page-link,
        .card-body .pagination .page-link {
            font-size: 0.875rem !important;
            padding: 0.375rem 0.75rem !important;
            min-width: 40px !important;
            height: 40px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .content-wrapper .pagination .page-link i,
        .main-content .pagination .page-link i,
        .card-body .pagination .page-link i {
            font-size: 0.75rem !important;
            line-height: 1 !important;
            margin: 0 !important;
        }

        /* ========== SORTING INDICATORS ========== */
        .products-table th a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            text-decoration: none;
            color: inherit;
        }

        .products-table th a:hover {
            color: #007bff;
        }

        .products-table th a i {
            font-size: 0.75em;
            margin-left: 5px;
            flex-shrink: 0;
        }

        /* ========== EMPTY STATE ========== */
        .empty-state {
            padding: 3rem 1rem;
            text-align: center;
            color: #6c757d;
        }

        .empty-state i {
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .empty-state h5 {
            margin-top: 1rem;
            margin-bottom: 0.5rem;
        }

        /* ========== TABLE RESPONSIVE ========== */
        .table-responsive {
            border: none;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* ========== MOBILE RESPONSIVE (320px - 576px) ========== */
        @media (max-width: 576px) {
            /* Hide less important columns */
            .products-table th:nth-child(1), /* ID */
            .products-table td:nth-child(1),
            .products-table th:nth-child(5), /* SubCategory */
            .products-table td:nth-child(5),
            .products-table th:nth-child(6), /* PSM Code */
            .products-table td:nth-child(6),
            .products-table th:nth-child(7), /* Created At */
            .products-table td:nth-child(7) {
                display: none;
            }

            .products-table thead th,
            .products-table tbody td {
                padding: 8px 4px;
                font-size: 0.75rem;
            }

            .badge {
                font-size: 0.65rem;
                padding: 0.25em 0.5em;
            }

            .btn-group .btn-sm {
                padding: 0.25rem 0.4rem;
                font-size: 0.75rem;
            }

            .btn-group .btn-sm i {
                margin: 0;
            }

            /* Pagination on mobile */
            .pagination {
                font-size: 0.75rem;
                justify-content: center;
            }

            .pagination .page-link {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
                margin: 1px;
                min-width: 32px;
            }

            .pagination .page-item:first-child .page-link,
            .pagination .page-item:last-child .page-link {
                padding: 0.25rem 0.5rem;
            }

            /* Fix mobile chevron icons */
            .pagination .page-link i,
            .pagination .page-link .fa,
            .pagination .page-link .fas {
                font-size: 0.65rem !important;
                line-height: 1 !important;
                margin: 0 !important;
            }

            .d-flex {
                flex-direction: column;
                align-items: center !important;
            }

            .text-muted {
                font-size: 0.75rem;
                margin-bottom: 0.5rem;
                text-align: center;
            }

            .card-header {
                flex-wrap: wrap;
            }

            .card-title {
                margin-bottom: 0.5rem;
            }

            .card-tools {
                width: 100%;
                text-align: center;
            }

            .card-tools .btn {
                width: 100%;
            }
        }

        /* ========== TABLET (577px - 768px) ========== */
        @media (min-width: 577px) and (max-width: 768px) {
            /* Hide ID column on tablet */
            .products-table th:nth-child(1),
            .products-table td:nth-child(1) {
                display: none;
            }

            .products-table thead th,
            .products-table tbody td {
                padding: 10px 6px;
                font-size: 0.8125rem;
            }

            .badge {
                font-size: 0.7rem;
            }

            .btn-group .btn-sm {
                padding: 0.3rem 0.45rem;
                font-size: 0.8125rem;
            }
        }

        /* ========== MEDIUM (769px - 1024px) ========== */
        @media (min-width: 769px) and (max-width: 1024px) {
            .products-table thead th,
            .products-table tbody td {
                padding: 11px 7px;
            }
        }

        /* ========== LARGE DESKTOP (1025px+) ========== */
        @media (min-width: 1025px) {
            .products-table thead th,
            .products-table tbody td {
                padding: 12px 8px;
            }
        }

        /* ========== TOUCH DEVICES ========== */
        @media (max-width: 768px) {
            .btn,
            .form-control {
                min-height: 44px;
            }

            .btn-group .btn-sm {
                min-height: 38px;
            }
        }

        /* ========== PRINT STYLES ========== */
        @media print {
            .card-tools,
            .btn-group,
            .pagination,
            .form-inline {
                display: none !important;
            }

            .products-table {
                font-size: 10pt;
            }
        }
    </style>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            // Force pagination icon sizing
            function fixPaginationIcons() {
                $('.pagination .page-link i').each(function() {
                    $(this).css({
                        'font-size': '0.75rem',
                        'line-height': '1',
                        'margin': '0',
                        'padding': '0',
                        'width': 'auto',
                        'height': 'auto'
                    });
                });

                $('.pagination .page-link').each(function() {
                    $(this).css({
                        'font-size': '0.875rem',
                        'padding': '0.375rem 0.75rem',
                        'min-width': '40px',
                        'height': '40px',
                        'display': 'flex',
                        'align-items': 'center',
                        'justify-content': 'center'
                    });
                });
            }

            // Fix on page load
            fixPaginationIcons();

            // Fix on window resize
            $(window).on('resize', fixPaginationIcons);

            // Fix after any AJAX updates (if any)
            $(document).ajaxComplete(fixPaginationIcons);
        });
    </script>

    <!--
    If you want to use DataTables instead of Laravel pagination, uncomment the following:

    <script>
        $(document).ready(function() {
            // Initialize DataTable with AdminLTE styling
            DataTablesAdminLTE.init('#productsTable', {
                "pageLength": 25,
                "order": [[0, "desc"]]
            }, [7], [7]); // Actions column (index 7) is not sortable or searchable
        });
    </script>
    -->
@stop


