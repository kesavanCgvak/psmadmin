/**
 * DataTables AdminLTE Configuration
 * Provides consistent styling and behavior for DataTables across the admin panel
 */

// Default DataTables configuration for AdminLTE
window.DataTablesAdminLTE = {
    defaultOptions: {
        "responsive": true,
        "lengthChange": true,
        "autoWidth": false,
        "scrollX": true,
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        "order": [[0, "desc"]],
        "language": {
            "search": "Search:",
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries",
            "infoEmpty": "No entries available",
            "infoFiltered": "(filtered from _MAX_ total entries)",
            "zeroRecords": "No matching records found",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            }
        },
        "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
               '<"row"<"col-sm-12"tr>>' +
               '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        "pagingType": "full_numbers",
        "buttons": ["copy", "csv", "excel", "pdf", "print", "colvis"],
        "columnDefs": [
            { "orderable": false, "targets": [] }, // Will be set dynamically
            { "searchable": false, "targets": [] }  // Will be set dynamically
        ]
    },

    /**
     * Initialize DataTable with AdminLTE styling
     * @param {string} selector - CSS selector for the table
     * @param {object} options - DataTables options (will be merged with defaults)
     * @param {array} nonSortableColumns - Array of column indices that should not be sortable
     * @param {array} nonSearchableColumns - Array of column indices that should not be searchable
     */
    init: function(selector, options = {}, nonSortableColumns = [], nonSearchableColumns = []) {
        const defaultOptions = { ...this.defaultOptions };

        // Merge custom options
        const mergedOptions = { ...defaultOptions, ...options };

        // Set non-sortable and non-searchable columns
        mergedOptions.columnDefs[0].targets = nonSortableColumns;
        mergedOptions.columnDefs[1].targets = nonSearchableColumns;

        // Initialize DataTable
        const table = $(selector).DataTable(mergedOptions);

        // Style the buttons container
        table.buttons().container().appendTo($(selector + '_wrapper .col-md-6:eq(0)'));

        // Add custom classes for styling
        $(selector + '_wrapper .dataTables_paginate').addClass('pagination-sm');
        $(selector + '_wrapper .dataTables_info').addClass('text-muted');

        return table;
    },

    /**
     * Apply AdminLTE styling to existing DataTable
     * @param {string} selector - CSS selector for the table wrapper
     */
    applyStyling: function(selector) {
        const wrapper = $(selector);

        // Style pagination
        wrapper.find('.dataTables_paginate').addClass('pagination-sm');
        wrapper.find('.dataTables_paginate .paginate_button').each(function() {
            if ($(this).hasClass('previous')) {
                $(this).html('<i class="fas fa-chevron-left"></i> Previous');
            } else if ($(this).hasClass('next')) {
                $(this).html('Next <i class="fas fa-chevron-right"></i>');
            }
        });

        // Style info
        wrapper.find('.dataTables_info').addClass('text-muted');

        // Style search and length controls
        wrapper.find('.dataTables_filter input').addClass('form-control-sm');
        wrapper.find('.dataTables_length select').addClass('form-control-sm');
    }
};

// Auto-apply styling when DataTables are initialized
$(document).ready(function() {
    // Apply styling to any existing DataTables
    $('.dataTables_wrapper').each(function() {
        DataTablesAdminLTE.applyStyling(this);
    });

    // Apply styling to new DataTables when they're created
    $(document).on('init.dt', function(e, settings) {
        const tableId = settings.nTable.id;
        DataTablesAdminLTE.applyStyling('#' + tableId + '_wrapper');
    });
});

// Export for global use
window.DataTablesAdminLTE = DataTablesAdminLTE;
