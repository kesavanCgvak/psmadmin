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
                <a href="{{ route('products.create') }}" class="btn btn-warning btn-sm">
                    <i class="fas fa-plus"></i> Add New Product
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
                                        <a href="{{ route('products.show', $product) }}" class="btn btn-info btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="{{ route('products.edit', $product) }}" class="btn btn-warning btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form action="{{ route('products.destroy', $product) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this product?');">
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
            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="text-muted">
                    Showing {{ $products->firstItem() ?? 0 }} to {{ $products->lastItem() ?? 0 }} of {{ $products->total() }} products
                </div>
                <div class="pagination-wrapper">
                    {{ $products->appends(request()->query())->links() }}
                </div>
            </div>

            @if($products->count() == 0)
                <div class="empty-state">
                    <i class="fas fa-box-open fa-3x"></i>
                    <h5>No products found</h5>
                    <p>There are no products to display at the moment.</p>
                    <a href="{{ route('products.create') }}" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add First Product
                    </a>
                </div>
            @endif
        </div>
    </div>
@stop

@section('css')
    <style>
        .products-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .products-table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            padding: 12px 8px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .products-table tbody tr {
            transition: background-color 0.15s ease-in-out;
        }

        .products-table tbody tr:hover {
            background-color: #f8f9fa;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .products-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .products-table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        .products-table tbody td {
            padding: 10px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
            line-height: 1.4;
        }

        .badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            font-weight: 500;
        }

        .btn-group .btn-sm {
            padding: 0.25rem 0.5rem;
            margin: 0 1px;
        }

        .pagination {
            margin-bottom: 0;
            justify-content: center;
        }

        .pagination .page-link {
            color: #495057;
            background-color: #fff;
            border: 1px solid #dee2e6;
            padding: 0.375rem 0.75rem;
            margin: 0 2px;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.15s ease-in-out;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .pagination .page-link:hover {
            color: #fff;
            background-color: #007bff;
            border-color: #007bff;
            text-decoration: none;
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

        .pagination .page-item:first-child .page-link,
        .pagination .page-item:last-child .page-link {
            padding: 0.375rem 1rem;
            font-weight: 600;
        }

        .pagination .page-link i {
            font-size: 0.75rem;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* Sorting indicators */
        .products-table th a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            text-decoration: none !important;
        }

        .products-table th a:hover {
            text-decoration: none !important;
            color: #007bff !important;
        }

        .products-table th a i {
            font-size: 0.8em;
            margin-left: 5px;
        }

        /* Table improvements */
        .table-responsive {
            border: none;
        }

        .products-table {
            margin-bottom: 0;
        }

        /* Empty state styling */
        .empty-state {
            padding: 3rem 1rem;
            text-align: center;
            color: #6c757d;
        }

        .empty-state i {
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        /* DataTables Pagination Styling */
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.375rem 0.75rem !important;
            margin: 0 2px !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 4px !important;
            color: #495057 !important;
            background-color: #fff !important;
            font-size: 0.875rem !important;
            font-weight: 500 !important;
            text-decoration: none !important;
            transition: all 0.15s ease-in-out !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-width: 32px !important;
            height: 32px !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            color: #fff !important;
            background-color: #007bff !important;
            border-color: #007bff !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 2px 4px rgba(0,123,255,0.3) !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background-color: #007bff !important;
            border-color: #007bff !important;
            color: white !important;
            font-weight: 600 !important;
            box-shadow: 0 2px 4px rgba(0,123,255,0.3) !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            color: #6c757d !important;
            background-color: #fff !important;
            border-color: #dee2e6 !important;
            cursor: not-allowed !important;
            opacity: 0.6 !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
            color: #6c757d !important;
            background-color: #fff !important;
            border-color: #dee2e6 !important;
            transform: none !important;
            box-shadow: none !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.previous,
        .dataTables_wrapper .dataTables_paginate .paginate_button.next {
            padding: 0.375rem 1rem !important;
            font-weight: 600 !important;
        }

        .dataTables_wrapper .dataTables_paginate {
            text-align: center !important;
            margin-top: 1rem !important;
        }

        .dataTables_wrapper .dataTables_info {
            color: #6c757d !important;
            font-size: 0.875rem !important;
            margin-top: 0.5rem !important;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 1rem !important;
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #ced4da !important;
            border-radius: 4px !important;
            padding: 0.375rem 0.75rem !important;
            font-size: 0.875rem !important;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #007bff !important;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25) !important;
        }
    </style>
@stop

@section('js')
    <!-- No DataTables - using clean Laravel pagination -->

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


