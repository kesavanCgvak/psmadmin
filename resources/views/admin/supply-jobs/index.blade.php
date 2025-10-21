@extends('adminlte::page')

@section('title', 'Supply Jobs')

@section('content_header')
    <h1>Supply Jobs Management</h1>
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
            <h3 class="card-title">All Supply Jobs</h3>
            <div class="card-tools">
                <span class="badge badge-primary">{{ $supplyJobs->count() }} Total Jobs</span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="supplyJobsTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Rental Job</th>
                            <th>Provider Company</th>
                            <th>Client</th>
                            <th>Quote Price</th>
                            <th>Products</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($supplyJobs as $job)
                            <tr>
                                <td>{{ $job->id }}</td>
                                <td>
                                    @if($job->rentalJob)
                                        <strong>{{ $job->rentalJob->name }}</strong>
                                        <br><small class="text-muted">Job #{{ $job->rentalJob->id }}</small>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if($job->provider)
                                        <span class="badge badge-info">{{ $job->provider->name }}</span>
                                        @if($job->provider->city)
                                            <br><small class="text-muted">{{ $job->provider->city->name }}, {{ $job->provider->country?->name }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if($job->rentalJob && $job->rentalJob->user)
                                        <div>
                                            <strong>{{ $job->rentalJob->user->username }}</strong>
                                            @if($job->rentalJob->user->company)
                                                <br><span class="badge badge-secondary">{{ $job->rentalJob->user->company->name }}</span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if($job->quote_price)
                                        <strong class="text-success">${{ number_format($job->quote_price, 2) }}</strong>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-primary">{{ $job->products_count }}</span>
                                </td>
                                <td>
                                    <small>
                                        @if($job->packing_date)
                                            <i class="fas fa-box text-primary"></i> {{ \Carbon\Carbon::parse($job->packing_date)->format('M d') }}<br>
                                        @endif
                                        @if($job->delivery_date)
                                            <i class="fas fa-truck text-success"></i> {{ \Carbon\Carbon::parse($job->delivery_date)->format('M d') }}<br>
                                        @endif
                                        @if($job->return_date)
                                            <i class="fas fa-undo text-warning"></i> {{ \Carbon\Carbon::parse($job->return_date)->format('M d') }}
                                        @endif
                                        @if(!$job->packing_date && !$job->delivery_date && !$job->return_date)
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </small>
                                </td>
                                <td>
                                    @php
                                        $statusColors = [
                                            'pending' => 'warning',
                                            'negotiating' => 'info',
                                            'accepted' => 'success',
                                            'cancelled' => 'danger',
                                        ];
                                        $statusColor = $statusColors[$job->status] ?? 'secondary';
                                    @endphp
                                    <span class="badge badge-{{ $statusColor }}">{{ ucfirst($job->status ?? 'N/A') }}</span>
                                </td>
                                <td>{{ $job->created_at?->format('M d, Y') }}</td>
                                <td>
                                    <a href="{{ route('admin.supply-jobs.show', $job) }}" class="btn btn-info btn-sm" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            initResponsiveDataTable('supplyJobsTable', {
                "columnDefs": [
                    { "orderable": false, "targets": [9] },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": 9 }
                ],
                "order": [[0, "desc"]]
            });
        });
    </script>
@stop

