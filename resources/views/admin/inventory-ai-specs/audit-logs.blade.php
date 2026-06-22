@extends('adminlte::page')

@section('title', 'AI Specification Audit Logs')

@section('content_header')
    <h1>AI Specification Audit Logs</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
            <h3 class="card-title mb-2 mb-md-0">Audit history for AI and manual specification changes</h3>
            <div class="card-tools">
                <a href="{{ route('admin.ai-specifications.index') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-robot"></i> AI Reviews
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <label for="updatedByFilter">Updated By</label>
                    <select id="updatedByFilter" class="form-control">
                        <option value="">All</option>
                        <option value="AI">AI</option>
                        <option value="Manual">Manual</option>
                    </select>
                </div>
            </div>

            <table id="auditLogsTable" class="table table-bordered table-striped table-sm" style="width:100%">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Field</th>
                        <th>Old Value</th>
                        <th>New Value</th>
                        <th>Confidence</th>
                        <th>Source</th>
                        <th>Updated By</th>
                        <th>Reviewer</th>
                        <th>Created</th>
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
            var table = initResponsiveDataTable('auditLogsTable', {
                processing: true,
                serverSide: true,
                order: [[8, 'desc']],
                ajax: {
                    url: '{{ route('admin.ai-specifications.audit-logs.data') }}',
                    data: function (d) {
                        d.updated_by_filter = $('#updatedByFilter').val();
                    }
                },
                columns: [
                    { data: 'product_name', name: 'product_name' },
                    { data: 'field_name', name: 'field_name' },
                    { data: 'old_value', name: 'old_value' },
                    { data: 'new_value', name: 'new_value' },
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
                    { data: 'updated_by', name: 'updated_by' },
                    { data: 'reviewer_name', name: 'reviewer_name' },
                    { data: 'created_at', name: 'created_at' }
                ]
            });

            $('#updatedByFilter').on('change', function () {
                table.ajax.reload();
            });
        });
    </script>
@stop
