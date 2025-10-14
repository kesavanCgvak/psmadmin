@extends('adminlte::page')

@section('title', 'States / Provinces')

@section('content_header')
    <h1>States / Provinces Management</h1>
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
            <h3 class="card-title">All States / Provinces</h3>
            <div class="card-tools">
                <a href="{{ route('states.create') }}" class="btn btn-info btn-sm">
                    <i class="fas fa-plus"></i> Add New State/Province
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="statesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Country</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($states as $state)
                        <tr>
                            <td>{{ $state->id }}</td>
                            <td>{{ $state->name }}</td>
                            <td>
                                <span class="badge badge-success">{{ $state->country?->name ?? 'N/A' }}</span>
                            </td>
                            <td>{{ $state->code ?? 'N/A' }}</td>
                            <td>
                                <span class="badge badge-primary">{{ ucfirst($state->type) }}</span>
                            </td>
                            <td>{{ $state->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('states.show', $state) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('states.edit', $state) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('states.destroy', $state) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this state/province?');">
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
            $('#statesTable').DataTable({
                "responsive": true,
                "lengthChange": true,
                "autoWidth": false,
                "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"]
            }).buttons().container().appendTo('#statesTable_wrapper .col-md-6:eq(0)');
        });
    </script>
@stop

