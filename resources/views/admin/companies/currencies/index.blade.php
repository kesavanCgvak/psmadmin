@extends('adminlte::page')

@section('title', 'Currencies')

@section('content_header')
    <h1>Currencies Management</h1>
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
            <h3 class="card-title">All Currencies</h3>
            <div class="card-tools">
                <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>
                </button>
                <a href="{{ route('admin.currencies.create') }}" class="btn btn-success btn-sm">
                    <i class="fas fa-plus"></i> Add New Currency
                </a>
            </div>
        </div>
        <div class="card-body">
            <table id="currenciesTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" title="Select All">
                        </th>
                        <th>ID</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Symbol</th>
                        <th>Companies Using</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($currencies as $currency)
                        <tr>
                            <td>
                                <input type="checkbox" class="row-checkbox" name="currency_ids[]" value="{{ $currency->id }}"
                                       data-name="{{ $currency->name }}">
                            </td>
                            <td>{{ $currency->id }}</td>
                            <td><span class="badge badge-primary">{{ $currency->code }}</span></td>
                            <td><strong>{{ $currency->name }}</strong></td>
                            <td><span style="font-size: 1.2em;">{{ $currency->symbol }}</span></td>
                            <td>
                                <span class="badge badge-info">{{ $currency->companies_count }}</span>
                            </td>
                            <td>{{ $currency->created_at?->format('M d, Y') }}</td>
                            <td>
                                <div class="btn-group">
                                    <a href="{{ route('admin.currencies.show', $currency) }}" class="btn btn-info btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.currencies.edit', $currency) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.currencies.destroy', $currency) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this currency?');">
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
            var table = initResponsiveDataTable('currenciesTable', {
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
                    alert('Please select at least one currency to delete.');
                    return;
                }

                var message = 'Are you sure you want to delete ' + selectedIds.length + ' currency/currencies?\n\n';
                message += 'Currencies to be deleted:\n';
                selectedNames.forEach(function(name, index) {
                    message += (index + 1) + '. ' + name + '\n';
                });
                message += '\nThis action cannot be undone!';

                if (confirm(message)) {
                    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

                    $.ajax({
                        url: '{{ route("admin.admin.currencies.bulk-delete") }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            currency_ids: selectedIds
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Successfully deleted ' + response.deleted_count + ' currency/currencies.');
                                location.reload();
                            } else {
                                alert('Error: ' + (response.message || 'Failed to delete currencies.'));
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred while deleting currencies.';
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

