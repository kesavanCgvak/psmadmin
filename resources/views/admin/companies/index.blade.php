@extends('adminlte::page')

@section('title', 'Companies')

@section('content_header')
    <h1>Companies Management</h1>
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
            <h3 class="card-title">All Companies</h3>
            <div class="card-tools">
                <a href="{{ route('admin.companies.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add New Company
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="companiesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Location</th>
                        <th>Currency</th>
                        <th>Rental Software</th>
                        <th>Users</th>
                        <th>Equipment</th>
                        <th>Rating</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($companies as $company)
                        <tr>
                            <td>{{ $company->id }}</td>
                            <td><strong>{{ $company->name }}</strong></td>
                            <td>
                                @if($company->city)
                                    {{ $company->city->name }},
                                @endif
                                {{ $company->country?->name ?? 'N/A' }}
                            </td>
                            <td>
                                @if($company->currency)
                                    <span class="badge badge-success">{{ $company->currency->code }}</span>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>
                                @if($company->rentalSoftware)
                                    <span class="badge badge-info">{{ $company->rentalSoftware->name }}</span>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td><span class="badge badge-primary">{{ $company->users_count }}</span></td>
                            <td><span class="badge badge-warning">{{ $company->equipments_count }}</span></td>
                            <td>
                                @php
                                    $rating = $company->rating ?? 0;
                                @endphp
                                @for($i = 1; $i <= 5; $i++)
                                    @if($i <= $rating)
                                        <i class="fas fa-star text-warning"></i>
                                    @else
                                        <i class="far fa-star text-muted"></i>
                                    @endif
                                @endfor
                            </td>
                            <td>{{ $company->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.companies.show', $company) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.companies.edit', $company) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.companies.destroy', $company) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this company? This will also delete all associated users and equipment.');">
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
            $('#companiesTable').DataTable({
                "responsive": true,
                "lengthChange": true,
                "autoWidth": false,
                "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"],
                "order": [[0, "desc"]]
            }).buttons().container().appendTo('#companiesTable_wrapper .col-md-6:eq(0)');
        });
    </script>
@stop

