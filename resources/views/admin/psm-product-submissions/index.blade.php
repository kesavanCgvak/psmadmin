@extends('adminlte::page')

@section('title', 'PSM Product Submissions')

@section('content_header')
    <h1>PSM Product Submissions</h1>
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
                User-submitted products awaiting catalog review
                @if($pendingCount > 0)
                    <span class="badge badge-warning ml-2">{{ $pendingCount }} pending</span>
                @endif
            </h3>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <label for="statusFilter">Status</label>
                    <select id="statusFilter" class="form-control">
                        @foreach($statuses as $value => $label)
                            <option value="{{ $value }}" {{ $value === \App\Models\PsmProductSubmission::STATUS_PENDING ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <table id="psmSubmissionsTable" class="table table-bordered table-striped table-sm" style="width:100%">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>PSM Code</th>
                        <th>Company</th>
                        <th>Submitted By</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
@stop

@section('js')
    <script>
        $(function () {
            var table = $('#psmSubmissionsTable').DataTable({
                processing: true,
                serverSide: true,
                pageLength: 25,
                order: [[5, 'desc']],
                ajax: {
                    url: @json(route('admin.psm-product-submissions.data')),
                    data: function (d) {
                        d.status_filter = $('#statusFilter').val();
                    }
                },
                columns: [
                    { data: 'name', name: 'name' },
                    { data: 'psm_code', name: 'psm_code' },
                    { data: 'company', name: 'company' },
                    { data: 'submitted_by', name: 'submitted_by' },
                    { data: 'status', name: 'status', orderable: true },
                    { data: 'created_at', name: 'created_at' },
                    { data: 'actions', name: 'actions', orderable: false, searchable: false }
                ]
            });

            $('#statusFilter').on('change', function () {
                table.ajax.reload();
            });
        });
    </script>
@stop
