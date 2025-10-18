@extends('adminlte::page')

@section('title', 'Cities')

@section('content_header')
    <h1>Cities Management</h1>
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
            <h3 class="card-title">All Cities</h3>
            <div class="card-tools">
                <a href="{{ route('cities.create') }}" class="btn btn-warning btn-sm">
                    <i class="fas fa-plus"></i> Add New City
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="citiesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>State/Province</th>
                        <th>Country</th>
                        <th>Latitude</th>
                        <th>Longitude</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cities as $city)
                        <tr>
                            <td>{{ $city->id }}</td>
                            <td>{{ $city->name }}</td>
                            <td>
                                @if($city->state)
                                    <span class="badge badge-info">{{ $city->state->name }}</span>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-success">{{ $city->country?->name ?? 'N/A' }}</span>
                            </td>
                            <td>{{ $city->latitude ? number_format($city->latitude, 4) : 'N/A' }}</td>
                            <td>{{ $city->longitude ? number_format($city->longitude, 4) : 'N/A' }}</td>
                            <td>{{ $city->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('cities.show', $city) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('cities.edit', $city) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('cities.destroy', $city) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this city?');">
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
            initResponsiveDataTable('citiesTable', {
                "columnDefs": [
                    { "orderable": false, "targets": -1 },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": -1 }
                ]
            });
        });
    </script>
@stop

