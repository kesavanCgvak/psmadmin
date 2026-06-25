@extends('adminlte::page')

@section('title', 'Rental Software Company Logos')

@section('content_header')
    <h1>Rental Software Company Logos</h1>
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
            <h3 class="card-title">All Rental Software Company Logos</h3>
            <div class="card-tools">
                <a href="{{ route('admin.rental-software-company-logos.create') }}" class="btn btn-info btn-sm">
                    <i class="fas fa-plus"></i> Add New Logo
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="rentalSoftwareCompanyLogosTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Logo</th>
                        <th>Company Name</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logos as $logo)
                        <tr>
                            <td>{{ $logo->id }}</td>
                            <td>
                                <img src="{{ $logo->logo_url }}"
                                     alt="{{ $logo->company_name }}"
                                     class="img-thumbnail"
                                     style="max-height: 48px; max-width: 120px; object-fit: contain;">
                            </td>
                            <td><strong>{{ $logo->company_name }}</strong></td>
                            <td>
                                @if($logo->is_active)
                                    <span class="badge badge-success">Active</span>
                                @else
                                    <span class="badge badge-secondary">Inactive</span>
                                @endif
                            </td>
                            <td>{{ $logo->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.rental-software-company-logos.edit', $logo) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.rental-software-company-logos.destroy', $logo) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this logo?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">No rental software company logos found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            initResponsiveDataTable('rentalSoftwareCompanyLogosTable', {
                "columnDefs": [
                    { "orderable": false, "targets": [1, -1] },
                    { "searchable": false, "targets": [1, -1] },
                    { "responsivePriority": 1, "targets": 2 },
                    { "responsivePriority": 2, "targets": -1 }
                ]
            });
        });
    </script>
@stop
