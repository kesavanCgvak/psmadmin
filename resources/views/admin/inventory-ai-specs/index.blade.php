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

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
            <h3 class="card-title mb-2 mb-md-0">
                Pending &amp; historical AI enrichment records
                @if($pendingCount > 0)
                    <span class="badge badge-warning ml-2">{{ $pendingCount }} pending</span>
                @endif
            </h3>
            <div class="card-tools">
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
            var table = initResponsiveDataTable('aiSpecsTable', {
                processing: true,
                serverSide: true,
                order: [[9, 'desc']],
                ajax: {
                    url: '{{ route('admin.ai-specifications.data') }}',
                    data: function (d) {
                        d.status_filter = $('#statusFilter').val();
                    }
                },
                columns: [
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
                ]
            });

            $('#statusFilter').on('change', function () {
                table.ajax.reload();
            });
        });
    </script>
@stop
