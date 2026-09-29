@extends('adminlte::page')

@section('title', 'Activity Logs')

@section('content_header')
    <h1>Activity Logs</h1>
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
            <h3 class="card-title mb-0">
                <i class="fas fa-clipboard-list"></i> Company inventory and integration changes
            </h3>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="mb-3 p-3 border rounded bg-light">
                <div class="row">
                    <div class="col-md-6 col-lg-3">
                        <div class="form-group">
                            <label for="company_id">Company</label>
                            <select name="company_id" id="company_id" class="form-control">
                                <option value="">All</option>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}" {{ (string) request('company_id') === (string) $company->id ? 'selected' : '' }}>
                                        {{ $company->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="form-group">
                            <label for="user">User</label>
                            <input type="text" name="user" id="user" class="form-control" placeholder="Username, email, or ID" value="{{ request('user') }}">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="action">Action</label>
                            <select name="action" id="action" class="form-control">
                                <option value="">All</option>
                                @foreach($actions as $action)
                                    <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ ucfirst($action) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="entity_type">Entity type</label>
                            <select name="entity_type" id="entity_type" class="form-control">
                                <option value="">All</option>
                                @foreach($entityTypes as $type => $label)
                                    <option value="{{ $type }}" {{ request('entity_type') === $type ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="entity_id">Entity ID</label>
                            <input type="number" name="entity_id" id="entity_id" class="form-control" min="1" value="{{ request('entity_id') }}">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="date_from">From date</label>
                            <input type="date" name="date_from" id="date_from" class="form-control" value="{{ request('date_from') }}">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group">
                            <label for="date_to">To date</label>
                            <input type="date" name="date_to" id="date_to" class="form-control" value="{{ request('date_to') }}">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-2">
                        <div class="form-group mb-0 w-100">
                            <label class="d-block invisible mb-2">Filter</label>
                            <div class="btn-group btn-group-sm d-flex" role="group">
                                <button type="submit" class="btn btn-primary w-50">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                                <a href="{{ route('admin.activity-logs.index') }}" class="btn btn-outline-secondary w-50">
                                    <i class="fas fa-undo"></i> Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table id="activityLogsTable" class="table table-bordered table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Company</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Entity ID</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($logs as $log)
                            <tr>
                                <td>{{ $log->created_at?->format($datetimeFormat) }}</td>
                                <td>
                                    @if($log->company)
                                        <a href="{{ route('admin.companies.show', $log->company) }}">{{ $log->company->name }}</a>
                                    @else
                                        <span class="text-muted">{{ $log->company_id ? 'Company #'.$log->company_id : '—' }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($log->user)
                                        <a href="{{ route('admin.users.show', $log->user) }}">{{ $log->user->username }}</a>
                                        @if($log->user->email)
                                            <small class="text-muted d-block">{{ $log->user->email }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">{{ $log->user_id ? 'User #'.$log->user_id : 'System' }}</span>
                                    @endif
                                </td>
                                <td>@include('admin.activity-logs._action', ['action' => $log->action])</td>
                                <td>{{ $log->entityLabel() }}</td>
                                <td>{{ $log->entity_id }}</td>
                                <td>
                                    <a href="{{ route('admin.activity-logs.show', $log) }}" class="btn btn-xs btn-info">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">No activity logs found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-center">
                {{ $logs->links() }}
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            initResponsiveDataTable('activityLogsTable', {
                paging: false,
                searching: false,
                info: false,
                lengthChange: false,
                ordering: true,
                columnDefs: [
                    { orderable: false, targets: [6] },
                    { responsivePriority: 1, targets: 0 },
                    { responsivePriority: 2, targets: 3 },
                    { responsivePriority: 3, targets: 4 }
                ]
            });
        });
    </script>
@stop
