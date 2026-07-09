@extends('adminlte::page')

@section('title', 'AI Specification Reviews')

@section('content_header')
    <h1>AI Specification Reviews</h1>
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

    <div id="bulkActionAlert" class="alert alert-dismissible fade show" style="display: none;">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <i class="icon fas fa-info-circle"></i> <span id="bulkActionAlertMessage"></span>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
            <h3 class="card-title mb-2 mb-md-0">
                Pending &amp; historical AI enrichment records
                @if($pendingCount > 0)
                    <span class="badge badge-warning ml-2">{{ $pendingCount }} pending</span>
                @endif
            </h3>
            <div class="card-tools">
                <button type="button" id="bulkApproveBtn" class="btn btn-success btn-sm" disabled>
                    <i class="fas fa-check"></i> Accept Selected
                </button>
                <button type="button" id="bulkRejectBtn" class="btn btn-danger btn-sm" disabled>
                    <i class="fas fa-times"></i> Reject Selected
                </button>
                <a href="{{ route('admin.ai-rejections.index') }}" class="btn btn-danger btn-sm">
                    <i class="fas fa-ban"></i> Rejected Products
                </a>
                <a href="{{ route('admin.ai-specifications.audit-logs') }}" class="btn btn-secondary btn-sm">
                    <i class="fas fa-history"></i> Audit History
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <label for="statusFilter">Status</label>
                    <select id="statusFilter" class="form-control">
                        @foreach($statuses as $value => $label)
                            <option value="{{ $value }}" {{ $value === \App\Models\InventoryMasterAiSpec::STATUS_PENDING ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <table id="aiSpecsTable" class="table table-bordered table-striped table-sm" style="width:100%">
                <thead>
                    <tr>
                        <th style="width:30px">
                            <input type="checkbox" id="selectAllCheckbox" title="Select all visible pending rows">
                        </th>
                        <th>ID</th>
                        <th>Product ID</th>
                        <th>Product Name</th>
                        <th>Manufacturer</th>
                        <th>Category</th>
                        <th>Existing Dims</th>
                        <th>Existing Weight</th>
                        <th>AI Dims</th>
                        <th>AI Weight</th>
                        <th>Confidence</th>
                        <th>Source</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(function () {
            var pendingStatus = @json(\App\Models\InventoryMasterAiSpec::STATUS_PENDING);
            var bulkActionInProgress = false;

            function getSelectedSpecIds() {
                var ids = [];
                $('#aiSpecsTable .row-select:checked').each(function () {
                    ids.push($(this).data('id'));
                });
                return ids;
            }

            function updateBulkActionButtons() {
                if (bulkActionInProgress) {
                    return;
                }

                var count = getSelectedSpecIds().length;
                $('#bulkApproveBtn, #bulkRejectBtn').prop('disabled', count === 0);

                if (count > 0) {
                    $('#bulkApproveBtn').html('<i class="fas fa-check"></i> Accept Selected (' + count + ')');
                    $('#bulkRejectBtn').html('<i class="fas fa-times"></i> Reject Selected (' + count + ')');
                } else {
                    $('#bulkApproveBtn').html('<i class="fas fa-check"></i> Accept Selected');
                    $('#bulkRejectBtn').html('<i class="fas fa-times"></i> Reject Selected');
                }
            }

            function syncSelectAllCheckbox() {
                var $selectable = $('#aiSpecsTable .row-select');
                var $checked = $('#aiSpecsTable .row-select:checked');
                $('#selectAllCheckbox').prop(
                    'checked',
                    $selectable.length > 0 && $selectable.length === $checked.length
                );
            }

            function showBulkActionAlert(type, message) {
                var $alert = $('#bulkActionAlert');
                $alert.removeClass('alert-success alert-danger alert-warning')
                    .addClass('alert-' + type)
                    .show();
                $('#bulkActionAlertMessage').text(message);
            }

            function setBulkActionLoading(isLoading) {
                bulkActionInProgress = isLoading;
                $('#bulkApproveBtn, #bulkRejectBtn, #selectAllCheckbox, #statusFilter').prop('disabled', isLoading);
                $('#aiSpecsTable .row-select').prop('disabled', isLoading);

                if (isLoading) {
                    $('#bulkApproveBtn').html('<i class="fas fa-spinner fa-spin"></i> Processing...');
                    $('#bulkRejectBtn').html('<i class="fas fa-spinner fa-spin"></i> Processing...');
                } else {
                    updateBulkActionButtons();
                }
            }

            function runBulkAction(action) {
                var specIds = getSelectedSpecIds();
                if (specIds.length === 0) {
                    alert('Please select at least one pending product to ' + action + '.');
                    return;
                }

                var verb = action === 'approve' ? 'approve' : 'reject';
                var confirmMessage = action === 'approve'
                    ? 'Approve and apply AI suggested values for ' + specIds.length + ' selected product(s)?'
                    : 'Reject AI suggestions for ' + specIds.length + ' selected product(s)? Inventory master will not be updated.';

                if (!window.confirm(confirmMessage)) {
                    return;
                }

                var route = action === 'approve'
                    ? '{{ route('admin.ai-specifications.bulk-approve') }}'
                    : '{{ route('admin.ai-specifications.bulk-reject') }}';

                setBulkActionLoading(true);

                $.ajax({
                    url: route,
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        spec_ids: specIds
                    },
                    success: function (response) {
                        var alertType = response.failed && response.failed.length > 0 ? 'warning' : 'success';
                        showBulkActionAlert(alertType, response.message);

                        if (response.failed && response.failed.length > 0) {
                            console.warn('Bulk ' + verb + ' partial failures:', response.failed);
                        }

                        $('#selectAllCheckbox').prop('checked', false);
                        table.ajax.reload(function () {
                            setBulkActionLoading(false);
                        }, false);
                    },
                    error: function (xhr) {
                        var message = 'An error occurred while processing the selected products.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        showBulkActionAlert('danger', message);
                        setBulkActionLoading(false);
                    }
                });
            }

            var table = initResponsiveDataTable('aiSpecsTable', {
                processing: true,
                serverSide: true,
                order: [[10, 'desc']],
                ajax: {
                    url: '{{ route('admin.ai-specifications.data') }}',
                    data: function (d) {
                        d.status_filter = $('#statusFilter').val();
                    }
                },
                columns: [
                    {
                        data: 'id',
                        orderable: false,
                        searchable: false,
                        render: function (data, type, row) {
                            if (row.status !== pendingStatus) {
                                return '';
                            }

                            return '<input type="checkbox" class="row-select" data-id="' + data + '">';
                        }
                    },
                    { data: 'id', name: 'id' },
                    { data: 'product_id', name: 'product_id' },
                    { data: 'product_name', name: 'product_name' },
                    { data: 'manufacturer', name: 'manufacturer' },
                    { data: 'category', name: 'category' },
                    { data: 'existing_dimensions', name: 'existing_dimensions', orderable: false, searchable: false },
                    { data: 'existing_weight', name: 'existing_weight', orderable: false, searchable: false },
                    { data: 'suggested_dimensions', name: 'suggested_dimensions', orderable: false, searchable: false },
                    { data: 'suggested_weight', name: 'suggested_weight', orderable: false, searchable: false },
                    { data: 'confidence_score', name: 'confidence_score' },
                    {
                        data: 'source_url',
                        name: 'source_url',
                        orderable: false,
                        render: function (data) {
                            if (!data) return '—';
                            return '<a href="' + $('<div/>').text(data).html() + '" target="_blank" rel="noopener">Source</a>';
                        }
                    },
                    { data: 'created_at', name: 'created_at' },
                    { data: 'status_badge', name: 'status', orderable: true, searchable: false },
                    { data: 'actions', name: 'actions', orderable: false, searchable: false }
                ],
                drawCallback: function () {
                    syncSelectAllCheckbox();
                    updateBulkActionButtons();
                }
            });

            $('#statusFilter').on('change', function () {
                $('#selectAllCheckbox').prop('checked', false);
                table.ajax.reload();
            });

            $('#aiSpecsTable').on('change', '.row-select', function () {
                syncSelectAllCheckbox();
                updateBulkActionButtons();
            });

            $('#selectAllCheckbox').on('change', function () {
                var isChecked = $(this).is(':checked');
                $('#aiSpecsTable .row-select').prop('checked', isChecked);
                updateBulkActionButtons();
            });

            $('#bulkApproveBtn').on('click', function () {
                runBulkAction('approve');
            });

            $('#bulkRejectBtn').on('click', function () {
                runBulkAction('reject');
            });
        });
    </script>
@stop
