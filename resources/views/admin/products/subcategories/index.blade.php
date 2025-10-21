@extends('adminlte::page')

@section('title', 'Sub-Categories')

@section('content_header')
    <h1>Sub-Categories Management</h1>
@stop

@section('css')
    @include('partials.responsive-css')
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
            <h3 class="card-title">All Sub-Categories</h3>
            <div class="card-tools">
                <a href="{{ route('admin.subcategories.create') }}" class="btn btn-info btn-sm">
                    <i class="fas fa-plus"></i> Add New Sub-Category
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="subCategoriesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Products</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($subCategories as $subCategory)
                        <tr>
                            <td>{{ $subCategory->id }}</td>
                            <td><strong>{{ $subCategory->name }}</strong></td>
                            <td>
                                <span class="badge badge-primary">{{ $subCategory->category?->name ?? 'N/A' }}</span>
                            </td>
                            <td>
                                <span class="badge badge-success">{{ $subCategory->products_count }}</span>
                            </td>
                            <td>{{ $subCategory->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.subcategories.show', $subCategory) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.subcategories.edit', $subCategory) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.subcategories.destroy', $subCategory) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this sub-category?');">
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
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            initResponsiveDataTable('subCategoriesTable', {
                "columnDefs": [
                    { "orderable": false, "targets": -1 },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": -1 }
                ]
            });
        });
    </script>
@stop

