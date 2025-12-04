<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.3.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.3.0/js/responsive.bootstrap4.min.js"></script>
<script>
// Responsive DataTable initialization function
function initResponsiveDataTable(tableId, options = {}) {
    const defaultOptions = {
        "responsive": true,
        "lengthChange": true,
        "autoWidth": false,
        "scrollX": false,
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        "order": [[ 0, "desc" ]],
        "language": {
            "search": "Search:",
            "lengthMenu": "Show _MENU_",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries",
            "infoEmpty": "No entries available",
            "infoFiltered": "(filtered from _MAX_ total)",
            "zeroRecords": "No matching entries found",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Prev"
            }
        },
        "pagingType": "simple_numbers",
        "stateSave": true,
        "stateSaveCallback": function (settings, data) {
            // Store the state in localStorage with a specific key
            localStorage.setItem('DataTables_' + settings.nTable.id, JSON.stringify(data));
        },
        "stateLoadCallback": function (settings) {
            // Load the state from localStorage
            var state = localStorage.getItem('DataTables_' + settings.nTable.id);
            return state ? JSON.parse(state) : null;
        },
        "drawCallback": function() {
            // Ensure buttons are properly aligned
            $('.btn-group').each(function() {
                $(this).css('display', 'flex');
            });
        }
    };

    // Merge user options with defaults
    const mergedOptions = $.extend(true, {}, defaultOptions, options);

    // Initialize DataTable
    const table = $('#' + tableId).DataTable(mergedOptions);

    // Add responsive behavior for window resize
    $(window).on('resize', function() {
        table.columns.adjust().responsive.recalc();
    });

    // Add tooltip for truncated text
    $('#' + tableId + ' tbody').on('mouseenter', 'td', function() {
        var $cell = $(this);
        if (this.offsetWidth < this.scrollWidth && !$cell.attr('title')) {
            $cell.attr('title', $cell.text());
        }
    });

    return table;
}
</script>

