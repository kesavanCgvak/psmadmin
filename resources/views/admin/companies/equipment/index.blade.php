@extends('adminlte::page')

@section('title', 'All Equipment')

@section('content_header')
    <h1>Equipment Management</h1>
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
            <h3 class="card-title">All Equipment</h3>
            <div class="card-tools">
                <a href="{{ route('admin.equipment.create') }}" class="btn btn-warning btn-sm">
                    <i class="fas fa-plus"></i> Add New Equipment
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="equipmentTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company</th>
                        <th>Product</th>
                        <th>Brand</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Software Code</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($equipments as $equipment)
                        <tr>
                            <td>{{ $equipment->id }}</td>
                            <td>
                                <span class="badge badge-primary">{{ $equipment->company?->name ?? 'N/A' }}</span>
                            </td>
                            <td><strong>{{ $equipment->product->model }}</strong></td>
                            <td>
                                <span class="badge badge-success">{{ $equipment->product->brand?->name ?? 'N/A' }}</span>
                            </td>
                            <td>
                                <span class="badge badge-info">{{ $equipment->product->category?->name ?? 'N/A' }}</span>
                            </td>
                            <td><span class="badge badge-warning">{{ $equipment->quantity }}</span></td>
                            <td>${{ number_format($equipment->price, 2) }}</td>
                            <td>{{ $equipment->software_code ?? 'N/A' }}</td>
                            <td>{{ $equipment->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.equipment.show', $equipment) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.equipment.edit', $equipment) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.equipment.destroy', $equipment) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this equipment?');">
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
            initResponsiveDataTable('equipmentTable', {
                "columnDefs": [
                    { "orderable": false, "targets": -1 },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": -1 }
                ]
            });
        });
    </script>
@stop

