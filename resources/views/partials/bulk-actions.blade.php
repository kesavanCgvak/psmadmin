{{-- Bulk Actions Partial --}}
{{-- Usage: @include('partials.bulk-actions', ['tableId' => 'mytable', 'route' => 'admin.items.bulk-delete']) --}}

<script>
// Initialize bulk delete for any table
function initBulkDelete(tableId, deleteRoute, itemName = 'items') {
    var selectedItems = [];

    // Add checkbox column to table
    $('#' + tableId + ' thead tr:first').prepend('<th style="width: 40px;"><input type="checkbox" id="selectAll" title="Select All"></th>');
    $('#' + tableId + ' tbody tr').each(function() {
        var checkboxId = $(this).find('td:first').text() || 'item';
        var checkboxName = $(this).find('td:nth-child(2)').text() || checkboxId;
        $(this).prepend('<td><input type="checkbox" class="row-checkbox" name="item_ids[]" value="' + checkboxId + '" data-name="' + checkboxName + '"></td>');
    });

    // Add bulk delete button to card header
    var bulkDeleteBtn = '<button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" style="display: none; margin-right: 5px;"><i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span></button>';
    $('#' + tableId).closest('.card').find('.card-header .card-tools').prepend(bulkDeleteBtn);

    // Select All checkbox
    $('#selectAll').on('change', function() {
        $('.row-checkbox').prop('checked', $(this).prop('checked'));
        updateBulkDeleteButton();
    });

    // Individual checkbox change
    $(document).on('change', '.row-checkbox', function() {
        updateBulkDeleteButton();
        var totalCheckboxes = $('.row-checkbox').length;
        var checkedCheckboxes = $('.row-checkbox:checked').length;
        $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes);
    });

    function updateBulkDeleteButton() {
        var checked = $('.row-checkbox:checked');
        if (checked.length > 0) {
            $('#bulkDeleteBtn').show().html('<i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected (' + checked.length + ')</span><span class="d-lg-none">Delete (' + checked.length + ')</span>');
        } else {
            $('#bulkDeleteBtn').hide();
        }
    }

    // Bulk delete button click
    $('#bulkDeleteBtn').on('click', function() {
        var selectedIds = [];
        var selectedNames = [];
        $('.row-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
            selectedNames.push($(this).data('name'));
        });

        if (selectedIds.length === 0) {
            alert('Please select at least one ' + itemName + ' to delete.');
            return;
        }

        // Confirmation dialog
        var message = 'Are you sure you want to delete ' + selectedIds.length + ' ' + itemName + '(s)?\n\n';
        message += itemName + ' to be deleted:\n';
        selectedNames.slice(0, 10).forEach(function(name, index) {
            message += (index + 1) + '. ' + name + '\n';
        });
        if (selectedNames.length > 10) {
            message += '... and ' + (selectedNames.length - 10) + ' more\n';
        }
        message += '\nThis action cannot be undone!';

        if (confirm(message)) {
            // Show loading state
            $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

            // Submit bulk delete
            $.ajax({
                url: deleteRoute,
                method: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',
                    item_ids: selectedIds
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        alert('Successfully deleted ' + response.deleted_count + ' ' + itemName + '(s).');
                        // Reload the page to refresh the table
                        location.reload();
                    } else {
                        alert('Error: ' + (response.message || 'Failed to delete ' + itemName));
                    }
                },
                error: function(xhr) {
                    var message = 'An error occurred while deleting ' + itemName + '.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    alert(message);
                },
                complete: function() {
                    // Restore button state
                    $('#bulkDeleteBtn').prop('disabled', false).html('<i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>');
                }
            });
        }
    });
}
</script>
