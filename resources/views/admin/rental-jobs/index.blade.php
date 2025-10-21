@extends('adminlte::page')

@section('title', 'Rental Jobs')

@section('content_header')
    <h1>Rental Jobs Management</h1>
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
            <h3 class="card-title">All Rental Jobs</h3>
            <div class="card-tools">
                <span class="badge badge-primary">{{ $rentalJobs->count() }} Total Jobs</span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="rentalJobsTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Job Name</th>
                            <th>Created By</th>
                            <th>Company</th>
                            <th>Date Range</th>
                            <th>Delivery Address</th>
                            <th>Products</th>
                            <th>Supply Jobs</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rentalJobs as $job)
                            <tr>
                                <td>{{ $job->id }}</td>
                                <td><strong>{{ $job->name }}</strong></td>
                                <td>
                                    @if($job->user)
                                        <div>
                                            <strong>{{ $job->user->username }}</strong>
                                            @if($job->user->profile && $job->user->profile->email)
                                                <br><small class="text-muted">{{ $job->user->profile->email }}</small>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if($job->user && $job->user->company)
                                        <span class="badge badge-info">{{ $job->user->company->name }}</span>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    @if($job->from_date && $job->to_date)
                                        <div>
                                            <i class="fas fa-calendar-alt text-primary"></i>
                                            <small>
                                                {{ \Carbon\Carbon::parse($job->from_date)->format('M d, Y') }}
                                                <br>to {{ \Carbon\Carbon::parse($job->to_date)->format('M d, Y') }}
                                            </small>
                                        </div>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    <small>{{ Str::limit($job->delivery_address, 30) ?: 'N/A' }}</small>
                                </td>
                                <td>
                                    <span class="badge badge-primary">{{ $job->products_count }}</span>
                                </td>
                                <td>
                                    <span class="badge badge-success">{{ $job->supply_jobs_count }}</span>
                                </td>
                                <td>
                                    @php
                                        $statusColors = [
                                            'pending' => 'warning',
                                            'active' => 'primary',
                                            'completed' => 'success',
                                            'cancelled' => 'danger',
                                        ];
                                        $statusColor = $statusColors[$job->status] ?? 'secondary';
                                    @endphp
                                    <span class="badge badge-{{ $statusColor }}">{{ ucfirst($job->status ?? 'N/A') }}</span>
                                </td>
                                <td>{{ $job->created_at?->format('M d, Y') }}</td>
                                <td>
                                    <a href="{{ route('admin.rental-jobs.show', $job) }}" class="btn btn-info btn-sm" title="View Details">
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
            initResponsiveDataTable('rentalJobsTable', {
                "columnDefs": [
                    { "orderable": false, "targets": [10] },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": 10 }
                ],
                "order": [[0, "desc"]]
            });
        });
    </script>
@stop

