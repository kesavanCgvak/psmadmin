@extends('adminlte::page')

@section('title', 'All Equipment')

@section('content_header')
    <h1>Equipment Management</h1>
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
            <h3 class="card-title">All Equipment</h3>
            <div class="card-tools">
                <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>
                </button>
                <a href="{{ route('admin.equipment.create') }}" class="btn btn-warning btn-sm">
                    <i class="fas fa-plus"></i> Add New Equipment
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="equipmentTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" title="Select All">
                        </th>
                        <th>ID</th>
                        <th>Company</th>
                        <th>Product</th>
                        <th>Brand</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Software Code</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($equipments as $equipment)
                        <tr>
                            <td>
                                <input type="checkbox" class="row-checkbox" name="equipment_ids[]" value="{{ $equipment->id }}"
                                       data-name="{{ $equipment->product->model ?? 'Equipment #' . $equipment->id }}">
                            </td>
                            <td>{{ $equipment->id }}</td>
                            <td>
                                <span class="badge badge-primary">{{ $equipment->company?->name ?? 'N/A' }}</span>
                            </td>
                            <td><strong>{{ $equipment->product->model }}</strong></td>
                            <td>
                                <span class="badge badge-success">{{ $equipment->product->brand?->name ?? 'N/A' }}</span>
                            </td>
                            <td>
                                <span class="badge badge-info">{{ $equipment->product->category?->name ?? 'N/A' }}</span>
                            </td>
                            <td><span class="badge badge-warning">{{ $equipment->quantity }}</span></td>
                            <td>${{ number_format($equipment->price, 2) }}</td>
                            <td>{{ $equipment->software_code ?? 'N/A' }}</td>
                            <td>{{ $equipment->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.equipment.show', $equipment) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.equipment.edit', $equipment) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.equipment.destroy', $equipment) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this equipment?');">
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

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            var table = initResponsiveDataTable('equipmentTable', {
                "columnDefs": [
                    { "orderable": false, "targets": [0, -1] },
                    { "searchable": false, "targets": [0, -1] },
                    { "responsivePriority": 1, "targets": 3 },
                    { "responsivePriority": 2, "targets": -1 }
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
                    alert('Please select at least one equipment to delete.');
                    return;
                }

                var message = 'Are you sure you want to delete ' + selectedIds.length + ' equipment?\n\n';
                message += 'Equipment to be deleted:\n';
                selectedNames.forEach(function(name, index) {
                    message += (index + 1) + '. ' + name + '\n';
                });
                message += '\nThis action cannot be undone!';

                if (confirm(message)) {
                    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

                    $.ajax({
                        url: '{{ route("admin.admin.equipment.bulk-delete") }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            equipment_ids: selectedIds
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Successfully deleted ' + response.deleted_count + ' equipment.');
                                location.reload();
                            } else {
                                alert('Error: ' + (response.message || 'Failed to delete equipment.'));
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred while deleting equipment.';
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

