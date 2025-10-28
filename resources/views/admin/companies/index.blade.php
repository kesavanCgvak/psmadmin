@extends('adminlte::page')

@section('title', 'Companies')

@section('content_header')
    <h1>Companies Management</h1>
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
            <h3 class="card-title">All Companies</h3>
            <div class="card-tools">
                <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>
                </button>
                <a href="{{ route('admin.companies.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add New Company
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="companiesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" title="Select All">
                        </th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Location</th>
                        <th>Currency</th>
                        <th>Rental Software</th>
                        <th>Users</th>
                        <th>Equipment</th>
                        <th>Rating</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($companies as $company)
                        <tr>
                            <td>
                                <input type="checkbox" class="row-checkbox" name="company_ids[]" value="{{ $company->id }}"
                                       data-name="{{ $company->name }}">
                            </td>
                            <td>{{ $company->id }}</td>
                            <td><strong>{{ $company->name }}</strong></td>
                            <td>
                                @if($company->city)
                                    {{ $company->city->name }},
                                @endif
                                {{ $company->country?->name ?? 'N/A' }}
                            </td>
                            <td>
                                @if($company->currency)
                                    <span class="badge badge-success">{{ $company->currency->code }}</span>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td>
                                @if($company->rentalSoftware)
                                    <span class="badge badge-info">{{ $company->rentalSoftware->name }}</span>
                                @else
                                    <span class="text-muted">N/A</span>
                                @endif
                            </td>
                            <td><span class="badge badge-primary">{{ $company->users_count }}</span></td>
                            <td><span class="badge badge-warning">{{ $company->equipments_count }}</span></td>
                            <td>
                                @php
                                    $rating = $company->rating ?? 0;
                                @endphp
                                @for($i = 1; $i <= 5; $i++)
                                    @if($i <= $rating)
                                        <i class="fas fa-star text-warning"></i>
                                    @else
                                        <i class="far fa-star text-muted"></i>
                                    @endif
                                @endfor
                            </td>
                            <td>{{ $company->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.companies.show', $company) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.companies.edit', $company) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.companies.destroy', $company) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this company? This will also delete all associated users and equipment.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
            var table = initResponsiveDataTable('companiesTable', {
                "columnDefs": [
                    { "orderable": false, "targets": [0, 8, 10] },
                    { "responsivePriority": 1, "targets": 2 },
                    { "responsivePriority": 2, "targets": 10 }
                ]
            });

            // Bulk delete functionality
            $('#selectAll').on('change', function() {
                $('.row-checkbox').prop('checked', $(this).prop('checked'));
                updateBulkDeleteButton();
            });

            $(document).on('change', '.row-checkbox', function() {
                updateBulkDeleteButton();
                var totalCheckboxes = $('.row-checkbox').length;
                var checkedCheckboxes = $('.row-checkbox:checked').length;
                $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes);
            });

            function updateBulkDeleteButton() {
                var checked = $('.row-checkbox:checked');
                if (checked.length > 0) {
                    $('#bulkDeleteBtn').show().html('<i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected (' + checked.length + ')</span><span class="d-lg-none">Delete</span>');
                } else {
                    $('#bulkDeleteBtn').hide();
                }
            }

            $('#bulkDeleteBtn').on('click', function() {
                var selectedIds = [];
                var selectedNames = [];
                $('.row-checkbox:checked').each(function() {
                    selectedIds.push($(this).val());
                    selectedNames.push($(this).data('name'));
                });

                if (selectedIds.length === 0) {
                    alert('Please select at least one company to delete.');
                    return;
                }

                var message = 'Are you sure you want to delete ' + selectedIds.length + ' company/companies?\n\n';
                message += 'Companies to be deleted:\n';
                selectedNames.forEach(function(name, index) {
                    message += (index + 1) + '. ' + name + '\n';
                });
                message += '\nThis will also delete all associated users and equipment. This action cannot be undone!';

                if (confirm(message)) {
                    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

                    $.ajax({
                        url: '{{ route("admin.companies.bulk-delete") }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            company_ids: selectedIds
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Successfully deleted ' + response.deleted_count + ' company/companies.');
                                location.reload();
                            } else {
                                alert('Error: ' + (response.message || 'Failed to delete companies.'));
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred while deleting companies.';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            alert(message);
                        },
                        complete: function() {
                            $('#bulkDeleteBtn').prop('disabled', false).html('<i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>');
                        }
                    });
                }
            });
        });
    </script>
@stop

