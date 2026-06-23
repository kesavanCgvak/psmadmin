@extends('adminlte::page')

@section('title', 'Rejected AI Products')

@section('content_header')
    <h1>Rejected AI Products</h1>
@stop

@section('css')
    @include('partials.responsive-css')
    <style>
        #rerunLoadingOverlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
        }

        #rerunLoadingOverlay.is-active {
            display: flex;
        }

        #rerunLoadingOverlay .rerun-loader-card {
            background: #fff;
            border-radius: 8px;
            padding: 2rem 2.5rem;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            max-width: 420px;
            width: 90%;
        }

        #rerunLoadingOverlay .rerun-loader-card .spinner-border {
            width: 3rem;
            height: 3rem;
        }
    </style>
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

    @if(session('rerun_results'))
        <div class="card card-outline card-info">
            <div class="card-header">
                <h3 class="card-title">Re-run Results</h3>
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Outcome</th>
                            <th>Status</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(session('rerun_results') as $row)
                            <tr>
                                <td>{{ $row['product_id'] }}</td>
                                <td>{{ $row['product_name'] }}</td>
                                <td>
                                    @php
                                        $badge = match ($row['outcome']) {
                                            'Success' => 'success',
                                            'Still Rejected' => 'warning',
                                            'Skipped' => 'secondary',
                                            default => 'danger',
                                        };
                                    @endphp
                                    <span class="badge badge-{{ $badge }}">{{ $row['outcome'] }}</span>
                                </td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
            <h3 class="card-title mb-2 mb-md-0">
                Products excluded from future batch runs
                @if($totalRejected > 0)
                    <span class="badge badge-danger ml-2">{{ $totalRejected }}</span>
                @endif
            </h3>
            <div class="card-tools">
                <a href="{{ route('admin.ai-specifications.index') }}" class="btn btn-secondary btn-sm">
                    <i class="fas fa-robot"></i> AI Reviews
                </a>
            </div>
        </div>
        <div class="card-body">
            <form id="rerunForm" method="POST" action="{{ route('admin.ai-rejections.rerun') }}">
                @csrf
                <input type="hidden" name="confirm_rerun" id="confirmRerun" value="0">

                <div class="row mb-3 align-items-end">
                    <div class="col-md-3">
                        <label for="categoryFilter">Category</label>
                        <select id="categoryFilter" class="form-control">
                            <option value="all">All categories</option>
                            @foreach($categories as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-9 text-md-right">
                        <button type="button" id="selectAllVisibleBtn" class="btn btn-outline-secondary btn-sm">
                            Select visible
                        </button>
                        <button type="button" id="clearSelectionBtn" class="btn btn-outline-secondary btn-sm">
                            Clear selection
                        </button>
                        <button type="button" id="rerunBtn" class="btn btn-primary btn-sm" disabled>
                            <i class="fas fa-redo"></i> Re-run Enrichment (sync)
                        </button>
                        <small class="d-block text-muted mt-1">
                            Max {{ $maxSyncRerun }} products per synchronous re-run. Confirmation required.
                        </small>
                    </div>
                </div>

                <table id="rejectedProductsTable" class="table table-bordered table-striped table-sm" style="width:100%">
                    <thead>
                        <tr>
                            <th style="width:30px">
                                <input type="checkbox" id="selectAllCheckbox" title="Select all on this page">
                            </th>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Rejection Reason</th>
                            <th>Category</th>
                            <th>Rejected Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </form>
        </div>
    </div>

    <div id="rerunLoadingOverlay" aria-live="polite" aria-busy="true">
        <div class="rerun-loader-card">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="sr-only">Loading...</span>
            </div>
            <h5 class="mb-2">Running AI Enrichment</h5>
            <p class="text-muted mb-0" id="rerunLoaderMessage">
                Processing selected products synchronously. Please do not close this page.
            </p>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(function () {
            var maxSyncRerun = {{ (int) $maxSyncRerun }};
            var selectedIds = {};

            function showRerunLoader(count) {
                $('#rerunLoaderMessage').text(
                    'Processing ' + count + ' product(s) synchronously. Please do not close this page.'
                );
                $('#rerunLoadingOverlay').addClass('is-active');
                $('#rerunBtn').prop('disabled', true).html(
                    '<i class="fas fa-spinner fa-spin"></i> Re-running (' + count + ')...'
                );
                $('#selectAllVisibleBtn, #clearSelectionBtn, #categoryFilter').prop('disabled', true);
                $('#rejectedProductsTable .row-select, #selectAllCheckbox').prop('disabled', true);
            }

            function updateRerunButton() {
                if ($('#rerunLoadingOverlay').hasClass('is-active')) {
                    return;
                }

                var count = Object.keys(selectedIds).length;
                $('#rerunBtn').prop('disabled', count === 0);
                $('#rerunBtn').html('<i class="fas fa-redo"></i> Re-run Enrichment (' + count + ')');
            }

            function syncHiddenInputs() {
                $('#rerunForm input[name="product_ids[]"]').remove();
                Object.keys(selectedIds).forEach(function (id) {
                    $('#rerunForm').append(
                        $('<input>', { type: 'hidden', name: 'product_ids[]', value: id })
                    );
                });
            }

            var table = initResponsiveDataTable('rejectedProductsTable', {
                processing: true,
                serverSide: true,
                order: [[5, 'desc']],
                ajax: {
                    url: '{{ route('admin.ai-rejections.data') }}',
                    data: function (d) {
                        d.category_filter = $('#categoryFilter').val();
                    }
                },
                columns: [
                    {
                        data: 'product_id',
                        orderable: false,
                        searchable: false,
                        render: function (data) {
                            var checked = selectedIds[data] ? 'checked' : '';
                            return '<input type="checkbox" class="row-select" data-id="' + data + '" ' + checked + '>';
                        }
                    },
                    { data: 'product_id', name: 'inventory_master_ai_rejections.inventory_master_id' },
                    { data: 'product_name', name: 'inventory_master_ai_rejections.product_name' },
                    {
                        data: 'rejection_reason',
                        name: 'inventory_master_ai_rejections.rejection_reason',
                        render: function (data) {
                            if (!data) return '—';
                            var text = $('<div/>').text(data).html();
                            return text.length > 120 ? text.substring(0, 120) + '…' : text;
                        }
                    },
                    { data: 'category_label', name: 'inventory_master_ai_rejections.rejection_category' },
                    { data: 'rejected_at', name: 'inventory_master_ai_rejections.rejected_at' },
                    { data: 'status_badge', name: 'status', orderable: false, searchable: false }
                ],
                drawCallback: function () {
                    $('#selectAllCheckbox').prop('checked', false);
                }
            });

            $('#categoryFilter').on('change', function () {
                table.ajax.reload();
            });

            $('#rejectedProductsTable').on('change', '.row-select', function () {
                var id = String($(this).data('id'));
                if (this.checked) {
                    if (Object.keys(selectedIds).length >= maxSyncRerun) {
                        $(this).prop('checked', false);
                        alert('You can select at most ' + maxSyncRerun + ' products for a synchronous re-run.');
                        return;
                    }
                    selectedIds[id] = true;
                } else {
                    delete selectedIds[id];
                }
                syncHiddenInputs();
                updateRerunButton();
            });

            $('#selectAllCheckbox, #selectAllVisibleBtn').on('click change', function () {
                var selectAll = this.id === 'selectAllCheckbox' ? $(this).is(':checked') : true;
                $('#rejectedProductsTable .row-select').each(function () {
                    var id = String($(this).data('id'));
                    if (selectAll) {
                        if (Object.keys(selectedIds).length >= maxSyncRerun) {
                            return false;
                        }
                        selectedIds[id] = true;
                        $(this).prop('checked', true);
                    } else {
                        delete selectedIds[id];
                        $(this).prop('checked', false);
                    }
                });
                if (this.id === 'selectAllCheckbox') {
                    $('#selectAllCheckbox').prop('checked', selectAll);
                }
                syncHiddenInputs();
                updateRerunButton();
            });

            $('#clearSelectionBtn').on('click', function () {
                selectedIds = {};
                $('#rejectedProductsTable .row-select').prop('checked', false);
                $('#selectAllCheckbox').prop('checked', false);
                syncHiddenInputs();
                updateRerunButton();
            });

            $('#rerunBtn').on('click', function () {
                var count = Object.keys(selectedIds).length;
                if (count === 0) {
                    return;
                }

                var message = 'Run synchronous AI enrichment for ' + count + ' selected product(s)?\n\n'
                    + 'This executes immediately in the browser request (not queued) and may take several minutes.';

                if (!window.confirm(message)) {
                    return;
                }

                $('#confirmRerun').val('1');
                syncHiddenInputs();
                showRerunLoader(count);
                $('#rerunForm').trigger('submit');
            });
        });
    </script>
@stop
