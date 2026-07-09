@extends('adminlte::page')

@section('title', 'SMS Logs')

@section('content_header')
    <h1>SMS Logs Management</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@php
    $statusColors = [
        'pending' => 'warning',
        'sent' => 'info',
        'delivered' => 'success',
        'failed' => 'danger',
    ];
@endphp

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
                <i class="fas fa-sms"></i> SMS Logs
            </h3>
            <div class="card-tools">
                <small class="text-muted">
                    Track and audit all SMS messages sent from the application
                </small>
            </div>
        </div>
        <div class="card-body">
            {{-- Filters --}}
            <form method="GET" action="{{ route('admin.sms-logs.index') }}" class="mb-4">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="">All</option>
                                @foreach($statuses as $status)
                                    <option value="{{ $status }}" {{ request('status') === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="provider">SMS Provider</label>
                            <select name="provider" id="provider" class="form-control">
                                <option value="">All</option>
                                @foreach($providers as $provider)
                                    <option value="{{ $provider }}" {{ request('provider') === $provider ? 'selected' : '' }}>{{ ucfirst($provider) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="related_type">Related Module</label>
                            <select name="related_type" id="related_type" class="form-control">
                                <option value="">All</option>
                                @foreach($relatedTypes as $type)
                                    <option value="{{ $type }}" {{ request('related_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="company_name">Company Name</label>
                            <input type="text" name="company_name" id="company_name" class="form-control" placeholder="Company..." value="{{ request('company_name') }}">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="contact_person_name">Contact Person</label>
                            <input type="text" name="contact_person_name" id="contact_person_name" class="form-control" placeholder="Contact..." value="{{ request('contact_person_name') }}">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label for="phone_number">Phone Number</label>
                            <input type="text" name="phone_number" id="phone_number" class="form-control" placeholder="Phone..." value="{{ request('phone_number') }}">
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
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="search">Search</label>
                            <input type="text" name="search" id="search" class="form-control" placeholder="Company, contact, phone, message, provider ID, record ID..." value="{{ request('search') }}">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-group mb-0 w-100">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-group mb-0 w-100">
                            <a href="{{ route('admin.sms-logs.index') }}" class="btn btn-default btn-block">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table id="smsLogsTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date &amp; Time</th>
                            <th>Company</th>
                            <th>Contact Person</th>
                            <th>Contact Mobile</th>
                            <th>Recipient</th>
                            <th>Phone Number</th>
                            <th>Message</th>
                            <th>Provider</th>
                            <th>Status</th>
                            <th>Module</th>
                            <th>Record ID</th>
                            <th>Provider Msg ID</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($smsLogs as $log)
                            <tr>
                                <td>{{ $log->id }}</td>
                                <td><small>{{ $log->created_at?->format($datetimeFormat) }}</small></td>
                                <td>{{ Str::limit($log->company_name ?? '-', 25) }}</td>
                                <td>{{ Str::limit($log->contact_person_name ?? '-', 20) }}</td>
                                <td><small>{{ $log->contact_person_mobile ?? '-' }}</small></td>
                                <td>{{ Str::limit($log->recipient_name ?? '-', 20) }}</td>
                                <td><small>{{ $log->phone_number ?? '-' }}</small></td>
                                <td>
                                    <span title="{{ $log->message }}">{{ Str::limit($log->message, 40) }}</span>
                                    @if(Str::length($log->message) > 40)
                                        <button type="button" class="btn btn-link btn-sm p-0 view-message"
                                            data-message="{{ e($log->message) }}" data-toggle="modal" data-target="#smsMessageModal">
                                            View
                                        </button>
                                    @endif
                                </td>
                                <td><span class="badge badge-secondary">{{ ucfirst($log->provider ?? '-') }}</span></td>
                                <td>
                                    @php $statusColor = $statusColors[$log->status] ?? 'secondary'; @endphp
                                    <span class="badge badge-{{ $statusColor }}">{{ ucfirst($log->status) }}</span>
                                </td>
                                <td>{{ $log->related_type ?? '-' }}</td>
                                <td>{{ $log->related_id ?? '-' }}</td>
                                <td><small>{{ $log->provider_message_id ?? '-' }}</small></td>
                                <td>
                                    <a href="{{ route('admin.sms-logs.show', $log) }}" class="btn btn-info btn-sm" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="14" class="text-center text-muted py-4">No SMS logs found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($smsLogs->hasPages())
                <div class="mt-3">
                    {{ $smsLogs->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Message preview modal --}}
    <div class="modal fade" id="smsMessageModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sms"></i> SMS Message</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p id="smsMessageBody" class="mb-0" style="white-space: pre-wrap;"></p>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            @if($smsLogs->isNotEmpty())
            initResponsiveDataTable('smsLogsTable', {
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

            $(document).on('click', '.view-message', function() {
                $('#smsMessageBody').text($(this).data('message'));
            });
        });
    </script>
@stop
