@extends('adminlte::page')

@section('title', 'Products Missing Specifications')

@section('content_header')
    <h1>Products Missing Specifications</h1>
@stop

@section('css')
    @include('partials.responsive-css')
    <style>
        #enrichLoadingOverlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
        }

        #enrichLoadingOverlay.is-active {
            display: flex;
        }

        #enrichLoadingOverlay .enrich-loader-card {
            background: #fff;
            border-radius: 8px;
            padding: 2rem 2.5rem;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            max-width: 420px;
            width: 90%;
        }

        #enrichLoadingOverlay .enrich-loader-card .spinner-border {
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

    @if(session('enrich_results'))
        <div class="card card-outline card-info">
            <div class="card-header">
                <h3 class="card-title">Enrichment Results</h3>
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
                        @foreach(session('enrich_results') as $row)
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
                Inventory products missing height, width, length, and/or weight
                @if($totalMissing > 0)
                    <span class="badge badge-warning ml-2">{{ $totalMissing }}</span>
                @endif
            </h3>
            <div class="card-tools">
                <a href="{{ route('admin.ai-specifications.index') }}" class="btn btn-secondary btn-sm">
                    <i class="fas fa-robot"></i> AI Reviews
                </a>
            </div>
        </div>
        <div class="card-body">
            <form id="enrichForm" method="POST" action="{{ route('admin.products-missing-specifications.enrich') }}">
                @csrf
                <input type="hidden" name="confirm_enrich" id="confirmEnrich" value="0">

                <div class="row mb-3 align-items-end">
                    <div class="col-md-3">
                        <label for="missingFieldFilter">Missing Field</label>
                        <select id="missingFieldFilter" class="form-control">
                            @foreach($missingFieldFilters as $value => $label)
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
                        <button type="button" id="enrichBtn" class="btn btn-primary btn-sm" disabled>
                            <i class="fas fa-robot"></i> Run AI Enrichment
                        </button>
                        <small class="d-block text-muted mt-1">
                            Max {{ $maxSyncEnrich }} products per run. Uses the same synchronous enrichment workflow as Rejected AI Products.
                        </small>
                    </div>
                </div>

                <table id="missingSpecsTable" class="table table-bordered table-striped table-sm" style="width:100%">
                    <thead>
                        <tr>
                            <th style="width:30px">
                                <input type="checkbox" id="selectAllCheckbox" title="Select all on this page">
                            </th>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>PSM Code</th>
                            <th>Height</th>
                            <th>Width</th>
                            <th>Length</th>
                            <th>Weight</th>
                            <th>Missing</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </form>
        </div>
    </div>

    <div id="enrichLoadingOverlay" aria-live="polite" aria-busy="true">
        <div class="enrich-loader-card">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="sr-only">Loading...</span>
            </div>
            <h5 class="mb-2">Running AI Enrichment</h5>
            <p class="text-muted mb-0" id="enrichLoaderMessage">
                Processing selected products synchronously. Please do not close this page.
            </p>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(function () {
            var maxSyncEnrich = {{ (int) $maxSyncEnrich }};
            var selectedIds = {};

            function showEnrichLoader(count) {
                $('#enrichLoaderMessage').text(
                    'Processing ' + count + ' product(s) synchronously. Please do not close this page.'
                );
                $('#enrichLoadingOverlay').addClass('is-active');
                $('#enrichBtn').prop('disabled', true).html(
                    '<i class="fas fa-spinner fa-spin"></i> Enriching (' + count + ')...'
                );
                $('#selectAllVisibleBtn, #clearSelectionBtn, #missingFieldFilter').prop('disabled', true);
                $('#missingSpecsTable .row-select, #selectAllCheckbox').prop('disabled', true);
            }

            function updateEnrichButton() {
                if ($('#enrichLoadingOverlay').hasClass('is-active')) {
                    return;
                }

                var count = Object.keys(selectedIds).length;
                $('#enrichBtn').prop('disabled', count === 0);
                $('#enrichBtn').html('<i class="fas fa-robot"></i> Run AI Enrichment (' + count + ')');
            }

            function syncHiddenInputs() {
                $('#enrichForm input[name="product_ids[]"]').remove();
                Object.keys(selectedIds).forEach(function (id) {
                    $('#enrichForm').append(
                        $('<input>', { type: 'hidden', name: 'product_ids[]', value: id })
                    );
                });
            }

            var table = initResponsiveDataTable('missingSpecsTable', {
                processing: true,
                serverSide: true,
                order: [[1, 'asc']],
                ajax: {
                    url: '{{ route('admin.products-missing-specifications.data') }}',
                    data: function (d) {
                        d.missing_field_filter = $('#missingFieldFilter').val();
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
                    { data: 'product_id', name: 'inventory_master.id' },
                    { data: 'product_name', name: 'inventory_master.model' },
                    { data: 'psm_code', name: 'inventory_master.psm_code' },
                    { data: 'height', name: 'inventory_master.height' },
                    { data: 'width', name: 'inventory_master.width' },
                    { data: 'length', name: 'inventory_master.length' },
                    { data: 'weight', name: 'inventory_master.weight' },
                    { data: 'missing_status', name: 'missing_status', orderable: false, searchable: false }
                ],
                drawCallback: function () {
                    $('#selectAllCheckbox').prop('checked', false);
                }
            });

            $('#missingFieldFilter').on('change', function () {
                table.ajax.reload();
            });

            $('#missingSpecsTable').on('change', '.row-select', function () {
                var id = String($(this).data('id'));
                if (this.checked) {
                    if (Object.keys(selectedIds).length >= maxSyncEnrich) {
                        $(this).prop('checked', false);
                        alert('You can select at most ' + maxSyncEnrich + ' products per enrichment run.');
                        return;
                    }
                    selectedIds[id] = true;
                } else {
                    delete selectedIds[id];
                }
                syncHiddenInputs();
                updateEnrichButton();
            });

            $('#selectAllCheckbox, #selectAllVisibleBtn').on('click change', function () {
                var selectAll = this.id === 'selectAllCheckbox' ? $(this).is(':checked') : true;
                $('#missingSpecsTable .row-select').each(function () {
                    var id = String($(this).data('id'));
                    if (selectAll) {
                        if (Object.keys(selectedIds).length >= maxSyncEnrich) {
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
                updateEnrichButton();
            });

            $('#clearSelectionBtn').on('click', function () {
                selectedIds = {};
                $('#missingSpecsTable .row-select').prop('checked', false);
                $('#selectAllCheckbox').prop('checked', false);
                syncHiddenInputs();
                updateEnrichButton();
            });

            $('#enrichBtn').on('click', function () {
                var count = Object.keys(selectedIds).length;
                if (count === 0) {
                    alert('Please select at least one product to enrich.');
                    return;
                }

                var message = 'Run AI enrichment for ' + count + ' selected product(s)?\n\n'
                    + 'This executes immediately in the browser request (not queued) and may take several minutes.';

                if (!window.confirm(message)) {
                    return;
                }

                $('#confirmEnrich').val('1');
                syncHiddenInputs();
                showEnrichLoader(count);
                $('#enrichForm').trigger('submit');
            });
        });
    </script>
@stop
