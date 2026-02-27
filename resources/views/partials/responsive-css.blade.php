<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.bootstrap4.min.css">
<style>
/* ===============================================
   ADMIN PANEL - RESPONSIVE CSS
   =============================================== */

/* ========== BASE STYLES ========== */
.card {
    box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
    margin-bottom: 1rem;
}

.content-header h1 {
    font-size: 1.75rem;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* ========== DATATABLE RESPONSIVE ========== */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_processing,
.dataTables_wrapper .dataTables_paginate {
    color: #333;
    margin-bottom: 10px;
    font-size: 0.875rem;
}

.dataTables_wrapper .dataTables_length select,
.dataTables_wrapper .dataTables_filter input {
    padding: 0.375rem 0.75rem;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
    font-size: 0.875rem;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 0.375rem 0.75rem;
    margin-left: 2px;
    color: #007bff !important;
    border: 1px solid #dee2e6;
    background-color: #fff;
    border-radius: 0.25rem;
    font-size: 0.875rem;
}

.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    color: #0056b3 !important;
    background-color: #e9ecef;
    border-color: #adb5bd;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    color: #fff !important;
    background-color: #007bff;
    border-color: #007bff;
}

/* ========== TABLE BASE STYLES ========== */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table.dataTable {
    width: 100% !important;
}

table.dataTable thead th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    padding: 12px 8px;
    font-size: 0.875rem;
    white-space: nowrap;
}

table.dataTable tbody td {
    padding: 12px 8px;
    vertical-align: middle;
    font-size: 0.875rem;
    word-wrap: break-word;
}

table.dataTable tbody tr:hover {
    background-color: #f8f9fa !important;
}

/* ========== BADGES ========== */
.badge {
    font-size: 0.75rem;
    padding: 0.35em 0.65em;
    font-weight: 500;
    white-space: nowrap;
}

/* ========== ACTION BUTTONS - PERFECT ALIGNMENT ========== */
.btn-group {
    display: flex;
    flex-wrap: nowrap;
    gap: 2px;
    justify-content: center;
    align-items: center;
    width: 100%;
}

.btn-group .btn-sm {
    padding: 0.375rem 0.5rem;
    font-size: 0.875rem;
    min-width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.15s ease-in-out;
}

/* Ensure all action buttons have consistent sizing */
.btn-group .btn-info.btn-sm,
.btn-group .btn-warning.btn-sm,
.btn-group .btn-danger.btn-sm {
    min-width: 36px;
    height: 36px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    flex-shrink: 0;
}

/* Button icons - no margins for perfect centering */
.btn-group .btn-sm i {
    margin: 0;
    font-size: 0.875rem;
    line-height: 1;
}

/* Form buttons in btn-group (for delete buttons) */
.btn-group form {
    display: flex;
    margin: 0;
}

.btn-group form button {
    min-width: 36px;
    height: 36px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

/* Hover effects for better UX */
.btn-group .btn-sm:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}

.btn-group .btn-info.btn-sm:hover {
    background-color: #138496;
    border-color: #117a8b;
}

.btn-group .btn-warning.btn-sm:hover {
    background-color: #e0a800;
    border-color: #d39e00;
}

.btn-group .btn-danger.btn-sm:hover {
    background-color: #c82333;
    border-color: #bd2130;
}

/* ========== MOBILE RESPONSIVE (320px - 576px) ========== */
@media (max-width: 576px) {
    .content-header h1 {
        font-size: 1.25rem;
    }

    .card-header {
        padding: 0.75rem;
        flex-wrap: wrap;
    }

    .card-title {
        font-size: 1rem;
        margin-bottom: 0;
    }

    .card-body {
        padding: 0.75rem;
    }

    .card-footer {
        padding: 0.75rem;
    }

    .card-tools {
        margin-top: 0.5rem;
        width: 100%;
    }

    .form-group label {
        font-size: 0.875rem;
        margin-bottom: 0.25rem;
    }

    .form-control,
    select.form-control {
        font-size: 0.875rem;
        min-height: 44px;
    }

    .btn {
        font-size: 0.875rem;
        min-height: 44px;
    }

    .card-footer .btn:not(.btn-sm) {
        width: 100%;
        margin-bottom: 0.5rem;
    }

    .card-footer .btn:last-child {
        margin-bottom: 0;
    }

    table.dataTable thead th,
    table.dataTable tbody td {
        padding: 8px 4px;
        font-size: 0.75rem;
    }

    .badge {
        font-size: 0.65rem;
        padding: 0.25em 0.5em;
    }

    .btn-group .btn-sm {
        padding: 0;
        font-size: 0.75rem;
        min-width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .btn-group .btn-info.btn-sm,
    .btn-group .btn-warning.btn-sm,
    .btn-group .btn-danger.btn-sm {
        min-width: 32px;
        height: 32px;
        padding: 0;
    }

    .btn-group form button {
        min-width: 32px;
        height: 32px;
        padding: 0;
    }

    .btn-group .btn-sm i {
        margin: 0;
        font-size: 0.75rem;
    }

    .dataTables_wrapper .dataTables_filter input {
        width: 100% !important;
        margin-left: 0 !important;
        margin-top: 0.25rem;
    }

    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        text-align: center;
        font-size: 0.75rem;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }

    .row {
        margin-left: 0;
        margin-right: 0;
    }

    .row > [class*='col-'] {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }

    hr {
        margin: 1rem 0;
    }

    small.form-text {
        font-size: 0.75rem;
    }

    .alert {
        font-size: 0.875rem;
        padding: 0.75rem 1rem;
    }
}

/* ========== TABLET (577px - 768px) ========== */
@media (min-width: 577px) and (max-width: 768px) {
    .content-header h1 {
        font-size: 1.5rem;
    }

    .card-body {
        padding: 1rem;
    }

    .form-control {
        font-size: 0.9rem;
    }

    table.dataTable thead th,
    table.dataTable tbody td {
        padding: 10px 6px;
        font-size: 0.8125rem;
    }

    .btn-group .btn-sm {
        min-width: 34px;
        height: 34px;
        padding: 0;
        font-size: 0.8125rem;
    }

    .btn-group .btn-info.btn-sm,
    .btn-group .btn-warning.btn-sm,
    .btn-group .btn-danger.btn-sm {
        min-width: 34px;
        height: 34px;
        padding: 0;
    }

    .btn-group form button {
        min-width: 34px;
        height: 34px;
        padding: 0;
    }
}

/* ========== MEDIUM (769px - 1024px) ========== */
@media (min-width: 769px) and (max-width: 1024px) {
    .card-body {
        padding: 1rem;
    }
}

/* ========== LARGE DESKTOP (1025px+) ========== */
@media (min-width: 1025px) {
    .card-body {
        padding: 1.25rem;
    }
}

/* ========== TOUCH DEVICES ========== */
@media (max-width: 768px) {
    .btn,
    .form-control,
    select.form-control {
        min-height: 44px;
    }
}

/* ========== PAGINATION FIXES ========== */
/* Fix oversized chevron icons in pagination */
.pagination .page-link i,
.pagination .page-link .fa,
.pagination .page-link .fas {
    font-size: 0.75rem !important;
    line-height: 1 !important;
    margin: 0 !important;
    display: inline !important;
}

.pagination .fa-chevron-left,
.pagination .fa-chevron-right,
.pagination .fa-angle-left,
.pagination .fa-angle-right {
    font-size: 0.75rem !important;
    line-height: 1 !important;
}

/* Ensure pagination buttons are properly sized */
.pagination .page-link {
    padding: 0.375rem 0.75rem !important;
    font-size: 0.875rem !important;
    min-width: 40px !important;
    height: 40px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    line-height: 1 !important;
}

.pagination .page-item {
    margin: 0 2px !important;
}

/* Mobile pagination fixes */
@media (max-width: 576px) {
    .pagination .page-link i,
    .pagination .page-link .fa,
    .pagination .page-link .fas {
        font-size: 0.65rem !important;
    }

    .pagination .page-link {
        padding: 0.25rem 0.5rem !important;
        font-size: 0.75rem !important;
        min-width: 32px !important;
        height: 32px !important;
    }
}

/* ========== PRINT STYLES ========== */
@media print {
    .card-tools,
    .btn-group,
    .card-footer .btn,
    .dataTables_filter,
    .dataTables_length,
    .dataTables_paginate {
        display: none !important;
    }

    .card {
        box-shadow: none;
        border: 1px solid #dee2e6;
    }
}
</style>

