@extends('adminlte::page')

@section('title', 'Email Logs')

@section('content_header')
    <h1>Email Logs Management</h1>
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
            <h3 class="card-title">
                <i class="fas fa-inbox"></i> Email Logs
            </h3>
            <div class="card-tools">
                <small class="text-muted">
                    Track all emails sent from the application
                </small>
            </div>
        </div>
        <div class="card-body">
            {{-- Filters --}}
            <form method="GET" action="{{ route('admin.email-logs.index') }}" class="mb-4">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="">All</option>
                                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="sent" {{ request('status') === 'sent' ? 'selected' : '' }}>Sent</option>
                                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="email_type">Email Type</label>
                            <select name="email_type" id="email_type" class="form-control">
                                <option value="">All</option>
                                @foreach($emailTypes as $type)
                                    <option value="{{ $type }}" {{ request('email_type') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="date_from">From Date</label>
                            <input type="date" name="date_from" id="date_from" class="form-control" value="{{ request('date_from') }}">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="date_to">To Date</label>
                            <input type="date" name="date_to" id="date_to" class="form-control" value="{{ request('date_to') }}">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="search">Search</label>
                            <input type="text" name="search" id="search" class="form-control" placeholder="Email or subject..." value="{{ request('search') }}">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-group mb-0 w-100">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table id="emailLogsTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Subject</th>
                            <th>Email Type</th>
                            <th>Status</th>
                            <th>Failure Reason</th>
                            <th>Sent At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($emailLogs as $log)
                            <tr>
                                <td>{{ $log->id }}</td>
                                <td>
                                    <small>{{ Str::limit($log->from_email ?? '-', 30) }}</small>
                                </td>
                                <td>
                                    <small>{{ Str::limit($log->to_email, 35) }}</small>
                                </td>
                                <td>
                                    <span title="{{ $log->subject }}">
                                        {{ Str::limit($log->subject ?? '-', 40) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-secondary">{{ ucfirst($log->email_type ?? 'other') }}</span>
                                </td>
                                <td>
                                    @php
                                        $statusColors = [
                                            'pending' => 'warning',
                                            'sent' => 'success',
                                            'failed' => 'danger',
                                        ];
                                        $statusColor = $statusColors[$log->status] ?? 'secondary';
                                    @endphp
                                    <span class="badge badge-{{ $statusColor }}">{{ ucfirst($log->status) }}</span>
                                </td>
                                <td>
                                    @if($log->failure_reason)
                                        <small title="{{ $log->failure_reason }}">{{ Str::limit($log->failure_reason, 30) }}</small>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $log->created_at?->format('M d, Y H:i') }}
                                </td>
                                <td>
                                    <a href="{{ route('admin.email-logs.show', $log) }}" class="btn btn-info btn-sm" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No email logs found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($emailLogs->hasPages())
                <div class="mt-3">
                    {{ $emailLogs->links() }}
                </div>
            @endif
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            @if($emailLogs->isNotEmpty())
            initResponsiveDataTable('emailLogsTable', {
                "paging": false,
                "ordering": true,
                "info": false,
                "searching": false,
                "columnDefs": [
                    { "orderable": false, "targets": [-1] }
                ],
                "order": [[0, "desc"]]
            });
            @endif
        });
    </script>
@stop
