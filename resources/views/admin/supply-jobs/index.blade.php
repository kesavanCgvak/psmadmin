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
                                            'partially_accepted' => 'warning',
                                            'accepted' => 'success',
                                            'closed' => 'secondary',
                                            'cancelled' => 'danger',
                                            'completed' => 'success',
                                        ];
                                        $statusColor = $statusColors[$job->status] ?? 'secondary';
                                        $isAdminCancelled = $job->status === 'cancelled' && optional($job->cancelledByUser)->is_admin;
                                        $statusDisplay = $isAdminCancelled
                                            ? 'Admin Cancelled'
                                            : ucfirst(str_replace('_', ' ', $job->status ?? 'N/A'));
                                    @endphp
                                    <span class="badge badge-{{ $statusColor }}">{{ $statusDisplay }}</span>
                                </td>
                                <td>{{ $job->created_at?->format('M d, Y') }}</td>
                                <td>
                                    <a href="{{ route('admin.supply-jobs.show', $job) }}" class="btn btn-info btn-sm" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    @php
                                        $canAdminCancel = !in_array($job->status, ['completed', 'rated'], true) && !$isAdminCancelled;
                                    @endphp
                                    @if($canAdminCancel)
                                        <button
                                            type="button"
                                            class="btn btn-danger btn-sm js-open-admin-cancel-modal"
                                            title="Delete Supply Job"
                                            data-toggle="modal"
                                            data-target="#adminCancelSupplyJobModal"
                                            data-cancel-url="{{ route('admin.supply-jobs.admin-cancel', $job) }}"
                                            data-job-name="{{ $job->rentalJob?->name ? 'Job: ' . $job->rentalJob->name : 'Supply Job #' . $job->id }}"
                                        >
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="adminCancelSupplyJobModal" tabindex="-1" role="dialog" aria-labelledby="adminCancelSupplyJobModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form id="adminCancelSupplyJobForm" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="adminCancelSupplyJobModalLabel">Delete Supply Job</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2" id="adminCancelSupplyJobTarget">This supply job will be marked as Admin Cancelled.</p>
                        <div class="form-group">
                            <label for="admin_cancel_reason">Reason (optional)</label>
                            <textarea id="admin_cancel_reason" name="reason" rows="2" class="form-control" placeholder="Reason for admin deletion/cancellation"></textarea>
                        </div>
                        <div class="form-group form-check">
                            <input type="checkbox" name="send_delete_email" id="admin_cancel_send_delete_email" value="1" class="form-check-input" checked>
                            <label class="form-check-label" for="admin_cancel_send_delete_email">
                                Send "job deleted" email to renter and provider
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Supply Job
                        </button>
                    </div>
                </form>
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

            $('.js-open-admin-cancel-modal').on('click', function() {
                const cancelUrl = $(this).data('cancel-url');
                const jobName = $(this).data('job-name');

                $('#adminCancelSupplyJobForm').attr('action', cancelUrl);
                $('#adminCancelSupplyJobTarget').text(jobName + ' will be marked as Admin Cancelled.');
                $('#admin_cancel_reason').val('');
                $('#admin_cancel_send_delete_email').prop('checked', true);
            });
        });
    </script>
@stop

