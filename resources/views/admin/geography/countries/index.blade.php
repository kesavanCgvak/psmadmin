@extends('adminlte::page')

@section('title', 'Countries')

@section('content_header')
    <h1>Countries Management</h1>
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
            <h3 class="card-title">All Countries</h3>
            <div class="card-tools">
                <a href="{{ route('countries.create') }}" class="btn btn-success btn-sm">
                    <i class="fas fa-plus"></i> Add New Country
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="countriesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Region</th>
                        <th>ISO Code</th>
                        <th>Phone Code</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($countries as $country)
                        <tr>
                            <td>{{ $country->id }}</td>
                            <td>{{ $country->name }}</td>
                            <td>
                                <span class="badge badge-primary">{{ $country->region?->name ?? 'N/A' }}</span>
                            </td>
                            <td><span class="badge badge-secondary">{{ $country->iso_code }}</span></td>
                            <td>{{ $country->phone_code ?? 'N/A' }}</td>
                            <td>{{ $country->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('countries.show', $country) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('countries.edit', $country) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('countries.destroy', $country) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this country?');">
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
            $('#countriesTable').DataTable({
                "responsive": true,
                "lengthChange": true,
                "autoWidth": false,
                "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
            }).buttons().container().appendTo('#countriesTable_wrapper .col-md-6:eq(0)');
        });
    </script>
@stop

