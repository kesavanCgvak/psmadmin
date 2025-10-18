@extends('adminlte::page')

@section('title', 'Currencies')

@section('content_header')
    <h1>Currencies Management</h1>
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
            <h3 class="card-title">All Currencies</h3>
            <div class="card-tools">
                <a href="{{ route('admin.currencies.create') }}" class="btn btn-success btn-sm">
                    <i class="fas fa-plus"></i> Add New Currency
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="currenciesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Symbol</th>
                        <th>Companies Using</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($currencies as $currency)
                        <tr>
                            <td>{{ $currency->id }}</td>
                            <td><span class="badge badge-primary">{{ $currency->code }}</span></td>
                            <td><strong>{{ $currency->name }}</strong></td>
                            <td><span style="font-size: 1.2em;">{{ $currency->symbol }}</span></td>
                            <td>
                                <span class="badge badge-info">{{ $currency->companies_count }}</span>
                            </td>
                            <td>{{ $currency->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.currencies.show', $currency) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.currencies.edit', $currency) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.currencies.destroy', $currency) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this currency?');">
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
            initResponsiveDataTable('currenciesTable', {
                "columnDefs": [
                    { "orderable": false, "targets": -1 },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": -1 }
                ]
            });
        });
    </script>
@stop

