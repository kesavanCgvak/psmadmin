@extends('adminlte::page')

@section('title', 'Rental Software')

@section('content_header')
    <h1>Rental Software Management</h1>
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
            <h3 class="card-title">All Rental Software</h3>
            <div class="card-tools">
                <a href="{{ route('admin.rental-software.create') }}" class="btn btn-info btn-sm">
                    <i class="fas fa-plus"></i> Add New Software
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="rentalSoftwareTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Version</th>
                        <th>Price</th>
                        <th>Companies Using</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rentalSoftwares as $software)
                        <tr>
                            <td>{{ $software->id }}</td>
                            <td><strong>{{ $software->name }}</strong></td>
                            <td>
                                @if($software->version)
                                    <span class="badge badge-secondary">v{{ $software->version }}</span>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>
                                @if($software->price)
                                    ${{ number_format($software->price, 2) }}
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-info">{{ $software->companies_count }}</span>
                            </td>
                            <td>{{ $software->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.rental-software.show', $software) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.rental-software.edit', $software) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.rental-software.destroy', $software) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this rental software?');">
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
    <script>
        $(document).ready(function() {
            $('#rentalSoftwareTable').DataTable({
                "responsive": true,
                "lengthChange": true,
                "autoWidth": false,
                "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
            }).buttons().container().appendTo('#rentalSoftwareTable_wrapper .col-md-6:eq(0)');
        });
    </script>
@stop

