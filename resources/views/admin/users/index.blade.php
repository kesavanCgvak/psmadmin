@extends('adminlte::page')

@section('title', 'User Management')

@section('content_header')
    <div class="row align-items-center">
        <div class="col">
            <h1 class="m-0">User Management</h1>
        </div>
        <div class="col-auto d-md-none">
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i>
            </a>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Users List</h3>
                    <div class="card-tools d-none d-md-block">
                        <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" style="display: none;">
                            <i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>
                        </button>
                        <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> <span class="d-none d-lg-inline">Add New User</span><span class="d-lg-none">Add</span>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="users-table" class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="selectAll" title="Select All">
                                </th>
                                <th>ID</th>
                                <th>Profile Picture</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Account Type</th>
                                <th>Role</th>
                                <th>Company</th>
                                <th>Email Status</th>
                                <th>Member Since</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($users as $user)
                                <tr>
                                    <td>
                                        <input type="checkbox" class="row-checkbox" name="user_ids[]" value="{{ $user->id }}"
                                               data-username="{{ $user->username }}">
                                    </td>
                                    <td>{{ $user->id }}</td>
                                    <td>
                                        <div class="profile-picture-container">
                                            @if($user->profile?->profile_picture && file_exists(public_path($user->profile->profile_picture)))
                                                <img src="{{ asset($user->profile->profile_picture) }}"
                                                     alt="{{ $user->username }}"
                                                     class="profile-picture"
                                                     style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%; border: 2px solid #dee2e6;"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            @endif
                                            <div class="profile-initial {{ $user->profile?->profile_picture && file_exists(public_path($user->profile->profile_picture)) ? 'd-none' : 'd-flex' }} align-items-center justify-content-center"
                                                 style="width: 40px; height: 40px; border-radius: 50%; background-color: #007bff; color: white; font-size: 16px; font-weight: bold; border: 2px solid #dee2e6;">
                                                {{ strtoupper(substr($user->username, 0, 1)) }}
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $user->username }}</td>
                                    <td>
                                        @if($user->profile?->full_name)
                                            {{ $user->profile->full_name }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $user->profile?->email ?? $user->email }}</td>
                                    <td>
                                        @if($user->account_type === 'company')
                                            <span class="badge badge-success">Company</span>
                                        @elseif($user->account_type === 'individual')
                                            <span class="badge badge-info">Individual</span>
                                        @elseif($user->account_type === 'provider')
                                            <span class="badge badge-primary">Provider</span>
                                        @else
                                            <span class="badge badge-secondary">{{ $user->account_type ? ucfirst($user->account_type) : 'Not Set' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($user->role === 'admin')
                                            <span class="badge badge-success">Admin</span>
                                        @else
                                            <span class="badge badge-secondary">User</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($user->company?->name && $user->company?->id)
                                            <a href="{{ route('admin.companies.show', $user->company) }}?from=users"
                                               class="font-weight-bold js-users-company-link">{{ $user->company->name }}</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge badge-{{ $user->email_verified ? 'success' : 'warning' }}">
                                            <i class="fas fa-{{ $user->email_verified ? 'check' : 'times' }}"></i>
                                            {{ $user->email_verified ? 'Verified' : 'Unverified' }}
                                        </span>
                                    </td>
                                    <td>{{ $user->created_at?->format('M d, Y') ?? 'N/A' }}</td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="{{ route('admin.users.show', $user) }}" class="btn btn-info btn-sm">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-warning btn-sm">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            @if(auth()->user()?->role === 'super_admin')
                                                <button type="button"
                                                        class="btn btn-secondary btn-sm js-assume-identity"
                                                        title="Assume Identity"
                                                        aria-label="Assume Identity"
                                                        data-user-name="{{ $user->profile?->full_name ?: $user->username }}"
                                                        data-company-name="{{ $user->company?->name ?: 'No company on file' }}"
                                                        data-url="{{ route('admin.users.assume-identity', $user) }}">
                                                    <i class="fas fa-user-shield"></i>
                                                </button>
                                            @endif
                                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                                  style="display: inline;"
                                                  onsubmit="return confirm('Are you sure you want to delete this user?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-danger btn-sm">
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
            </div>
        </div>
    </div>

    @if(auth()->user()?->role === 'super_admin')
        <div class="modal fade" id="assumeIdentityModal" tabindex="-1" role="dialog" aria-labelledby="assumeIdentityTitle" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="assumeIdentityTitle">Assume Identity</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p id="assumeIdentityPrompt" class="mb-2"></p>
                        <p class="text-muted mb-0">This starts a read-only support session. It cannot change the user's data. The marketplace opens in a separate window, and the support credential is not put in the address.</p>
                        <div id="assumeIdentityAlert" class="alert mt-3 mb-0" style="display: none;" role="alert"></div>
                        <pre id="assumeIdentityDebug" class="small text-muted mt-3 mb-0" style="white-space: pre-wrap;"></pre>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="assumeIdentityConfirm">Start session</button>
                        <button type="button" class="btn btn-danger" id="assumeIdentityEnd" style="display: none;">End session</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@stop

@section('css')
    @include('partials.responsive-css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.bootstrap4.min.css">
    <style>
        /* Company column link */
        #users-table a.js-users-company-link {
            color: #007bff;
        }

        #users-table a.js-users-company-link:hover {
            color: #0056b3;
            text-decoration: underline;
        }

        /* ========== Base Layout ========== */
        .content-header h1 {
            font-size: 1.75rem;
        }

        .card {
            box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
        }

        .card-body {
            padding: 0.75rem;
        }

        /* ========== DataTables Controls ========== */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_processing,
        .dataTables_wrapper .dataTables_paginate {
            color: #333;
            margin-bottom: 10px;
            font-size: 0.875rem;
        }

        .dataTables_wrapper .dataTables_length select {
            padding: 0.25rem 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }

        .dataTables_wrapper .dataTables_filter input {
            padding: 0.375rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }

        /* ========== Pagination ========== */
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.375rem 0.75rem;
            margin-left: 2px;
            color: #007bff !important;
            border: 1px solid #dee2e6;
            background-color: #fff;
            border-radius: 0.25rem;
            text-decoration: none;
            display: inline-block;
            font-size: 0.875rem;
            line-height: 1.5;
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
            font-weight: 500;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            color: #6c757d !important;
            background-color: #fff;
            border-color: #dee2e6;
            cursor: not-allowed;
        }

        /* ========== Table Base Styles ========== */
        #users-table {
            width: 100% !important;
            border-collapse: collapse;
        }

        #users-table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            padding: 12px 8px;
            font-size: 0.875rem;
            white-space: nowrap;
        }

        #users-table tbody td {
            padding: 12px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
            font-size: 0.875rem;
        }

        #users-table tbody tr {
            transition: background-color 0.15s ease-in-out;
        }

        #users-table tbody tr:hover {
            background-color: #f8f9fa !important;
        }

        #users-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        #users-table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        /* ========== Text Wrapping & Truncation ========== */
        #users-table td {
            word-wrap: break-word;
            word-break: break-word;
            max-width: 200px;
        }

        /* Username column */
        #users-table td:nth-child(3) {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Email column */
        #users-table td:nth-child(5) {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Company column */
        #users-table td:nth-child(8) {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ========== Profile Picture ========== */
        .profile-picture-container {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            margin: 0 auto;
        }

        .profile-picture {
            transition: transform 0.2s ease-in-out;
        }

        .profile-picture:hover {
            transform: scale(1.1);
            border-color: #007bff !important;
        }

        .profile-initial {
            transition: background-color 0.15s ease-in-out;
        }

        .profile-initial:hover {
            background-color: #0056b3 !important;
        }

        /* ========== Badges ========== */
        .badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
            font-weight: 500;
            letter-spacing: 0.025em;
            white-space: nowrap;
        }

        /* ========== Action Buttons ========== */
        .btn-group {
            display: flex;
            flex-wrap: nowrap;
            gap: 2px;
        }

        .btn-group .btn-sm {
            padding: 0.375rem 0.5rem;
            font-size: 0.875rem;
        }

        .btn-group .btn-sm i {
            font-size: 0.875rem;
        }

        /* ========== Responsive Table Container ========== */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* ========== Mobile Responsive (320px - 576px) ========== */
        @media (max-width: 576px) {
            .content-header h1 {
                font-size: 1.25rem;
            }

            .card-body {
                padding: 0.5rem;
            }

            /* Hide less important columns on mobile */
            #users-table thead th:nth-child(1), /* ID */
            #users-table tbody td:nth-child(1),
            #users-table thead th:nth-child(4), /* Full Name */
            #users-table tbody td:nth-child(4),
            #users-table thead th:nth-child(7), /* Role */
            #users-table tbody td:nth-child(7),
            #users-table thead th:nth-child(9), /* Email Status */
            #users-table tbody td:nth-child(9),
            #users-table thead th:nth-child(10), /* Member Since */
            #users-table tbody td:nth-child(10) {
                display: none;
            }

            #users-table thead th,
            #users-table tbody td {
                padding: 8px 4px;
                font-size: 0.75rem;
            }

            .badge {
                font-size: 0.65rem;
                padding: 0.25em 0.5em;
            }

            .btn-group .btn-sm {
                padding: 0.25rem 0.4rem;
                font-size: 0.75rem;
            }

            .btn-group .btn-sm i {
                font-size: 0.75rem;
            }

            .profile-picture-container {
                width: 32px;
                height: 32px;
            }

            .profile-picture-container img,
            .profile-initial {
                width: 32px !important;
                height: 32px !important;
                font-size: 12px !important;
            }

            /* Stack DataTables controls */
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter {
                text-align: center;
                margin-bottom: 10px;
            }

            .dataTables_wrapper .dataTables_filter input {
                width: 100% !important;
                margin-left: 0 !important;
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
        }

        /* ========== Tablet (577px - 768px) ========== */
        @media (min-width: 577px) and (max-width: 768px) {
            .content-header h1 {
                font-size: 1.5rem;
            }

            .card-body {
                padding: 0.75rem;
            }

            /* Hide ID column on tablet */
            #users-table thead th:nth-child(1),
            #users-table tbody td:nth-child(1) {
                display: none;
            }

            #users-table thead th,
            #users-table tbody td {
                padding: 10px 6px;
                font-size: 0.8125rem;
            }

            .badge {
                font-size: 0.7rem;
                padding: 0.3em 0.55em;
            }

            .btn-group .btn-sm {
                padding: 0.3rem 0.45rem;
                font-size: 0.8125rem;
            }

            .profile-picture-container {
                width: 36px;
                height: 36px;
            }

            .profile-picture-container img,
            .profile-initial {
                width: 36px !important;
                height: 36px !important;
                font-size: 14px !important;
            }
        }

        /* ========== Medium Screens (769px - 1024px) ========== */
        @media (min-width: 769px) and (max-width: 1024px) {
            .card-body {
                padding: 1rem;
            }

            #users-table thead th,
            #users-table tbody td {
                padding: 11px 7px;
                font-size: 0.875rem;
            }

            .badge {
                font-size: 0.725rem;
            }
        }

        /* ========== Large Desktop (1025px+) ========== */
        @media (min-width: 1025px) {
            .card-body {
                padding: 1.25rem;
            }

            #users-table thead th,
            #users-table tbody td {
                padding: 12px 8px;
            }
        }

        /* ========== Text Utilities ========== */
        .text-muted {
            color: #6c757d !important;
            font-style: italic;
        }

        /* ========== Loading State ========== */
        .dataTables_processing {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 200px;
            margin-left: -100px;
            margin-top: -26px;
            text-align: center;
            padding: 1rem;
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        /* ========== Hover Effects ========== */
        tr.highlight {
            background-color: #fff3cd !important;
        }

        /* ========== Print Styles ========== */
        @media print {
            .card-tools,
            .btn-group,
            .dataTables_filter,
            .dataTables_length,
            .dataTables_paginate {
                display: none !important;
            }

            #users-table {
                font-size: 10pt;
            }
        }
    </style>
@stop

@section('js')
    @include('partials.admin-all-batches-js')
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.3.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.3.0/js/responsive.bootstrap4.min.js"></script>
    <script>
        $(document).ready(function() {
            var table = $('#users-table').DataTable({
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
                    "info": "Showing _START_ to _END_ of _TOTAL_ users",
                    "infoEmpty": "No users available",
                    "infoFiltered": "(filtered from _MAX_ total)",
                    "zeroRecords": "No matching users found",
                    "paginate": {
                        "first": "First",
                        "last": "Last",
                        "next": "Next",
                        "previous": "Prev"
                    }
                },
                "columnDefs": [
                    {
                        "orderable": false,
                        "targets": [0, 1, 11] // Checkbox, ID, Actions
                    },
                    {
                        "searchable": false,
                        "targets": [0, 1, 2, 11] // Checkbox, ID, Profile Picture, Actions
                    },
                    {
                        "responsivePriority": 1,
                        "targets": 3 // Username - always visible
                    },
                    {
                        "responsivePriority": 2,
                        "targets": 11 // Actions - always visible
                    },
                    {
                        "responsivePriority": 3,
                        "targets": [6, 7] // Email and Account Type
                    }
                ],
                "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                       '<"row"<"col-sm-12"tr>>' +
                       '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                "pagingType": "simple_numbers",
                "stateSave": true,
                "stateSaveCallback": function (settings, data) {
                    localStorage.setItem('DataTables_users-table', JSON.stringify(data));
                },
                "stateLoadCallback": function (settings) {
                    var raw = localStorage.getItem('DataTables_users-table');
                    if (!raw) {
                        return null;
                    }
                    var state = JSON.parse(raw);
                    if (state && Number(state.length) === -1) {
                        $(settings.nTable).data('adminRestoreAll', true);
                        state.length = 25;
                    }
                    return state;
                },
                "drawCallback": function() {
                    // Ensure buttons are properly aligned
                    $('.btn-group').each(function() {
                        $(this).css('display', 'flex');
                    });
                },
                "initComplete": function() {
                    var params = new URLSearchParams(window.location.search);
                    var pageParam = params.get('page');
                    var searchParam = params.get('search');
                    var changed = false;
                    if (searchParam !== null && searchParam !== '') {
                        table.search(searchParam);
                        changed = true;
                    }
                    if (pageParam !== null && pageParam !== '') {
                        var p = parseInt(pageParam, 10);
                        if (!isNaN(p) && p > 0) {
                            table.page(p - 1);
                            changed = true;
                        }
                    }
                    if (changed) {
                        table.draw(false);
                    }
                }
            });
            attachAdminAllBatches(table);

            // Company name → company detail with return context (page + global search)
            $('#users-table tbody').on('click', 'a.js-users-company-link', function(e) {
                e.preventDefault();
                var base = $(this).attr('href');
                var info = table.page.info();
                var u = new URL(base, window.location.origin);
                u.searchParams.set('from', 'users');
                var keyword = table.search();
                if (keyword) {
                    u.searchParams.set('search', keyword);
                } else {
                    u.searchParams.delete('search');
                }
                u.searchParams.set('page', (info.page + 1).toString());
                window.location.href = u.pathname + u.search;
            });
            $(window).on('resize', function() {
                table.columns.adjust().responsive.recalc();
            });

            // Add tooltip for truncated text
            $('#users-table tbody').on('mouseenter', 'td', function() {
                var $cell = $(this);
                if (this.offsetWidth < this.scrollWidth && !$cell.attr('title')) {
                    $cell.attr('title', $cell.text());
                }
            });

            // Bulk delete functionality
            var selectedUsers = [];

            // Select All checkbox
            $('#selectAll').on('change', function() {
                $('.row-checkbox').prop('checked', $(this).prop('checked'));
                updateBulkDeleteButton();
            });

            // Individual checkbox change
            $(document).on('change', '.row-checkbox', function() {
                updateBulkDeleteButton();
                // Update select all checkbox state
                var totalCheckboxes = $('.row-checkbox').length;
                var checkedCheckboxes = $('.row-checkbox:checked').length;
                $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes);
            });

            function updateBulkDeleteButton() {
                var checked = $('.row-checkbox:checked');
                if (checked.length > 0) {
                    $('#bulkDeleteBtn').show().text('Delete Selected (' + checked.length + ')');
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
                    selectedNames.push($(this).data('username'));
                });

                if (selectedIds.length === 0) {
                    alert('Please select at least one user to delete.');
                    return;
                }

                // Confirmation dialog
                var message = 'Are you sure you want to delete ' + selectedIds.length + ' user(s)?\n\n';
                message += 'Users to be deleted:\n';
                selectedNames.forEach(function(name, index) {
                    message += (index + 1) + '. ' + name + '\n';
                });
                message += '\nThis action cannot be undone!';

                if (confirm(message)) {
                    // Show loading state
                    $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

                    // Submit bulk delete
                    $.ajax({
                        url: '{{ route("admin.users.bulk-delete") }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            user_ids: selectedIds
                        },
                        success: function(response) {
                            if (response.success) {
                                // Show success message
                                alert('Successfully deleted ' + response.deleted_count + ' user(s).');
                                // Reload the page to refresh the table
                                location.reload();
                            } else {
                                alert('Error: ' + (response.message || 'Failed to delete users.'));
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred while deleting users.';
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

            @if(auth()->user()?->role === 'super_admin')
            @php
                $frontendConfigured = (string) config('app.frontend_url');
                $frontendWithoutHash = preg_replace('/#.*$/', '', $frontendConfigured) ?? $frontendConfigured;
                $frontendWithoutHash = rtrim($frontendWithoutHash, '/');
                $assumeIdentityUrl = $frontendWithoutHash.'/#/assume-identity';
                $frontendParts = parse_url($assumeIdentityUrl);
                $frontendOrigin = '';
                if (is_array($frontendParts) && isset($frontendParts['scheme'], $frontendParts['host'])) {
                    $frontendOrigin = $frontendParts['scheme'].'://'.$frontendParts['host'];
                    if (! empty($frontendParts['port'])) {
                        $frontendOrigin .= ':'.$frontendParts['port'];
                    }
                }
            @endphp
            var assumeUrl = null;
            var assumeSessionId = null;
            var assumeEndTemplate = @json(route('admin.support-sessions.end', ['supportSession' => '__ID__']));
            var assumeCsrf = @json(csrf_token());
            var assumeFrontendOrigin = @json($frontendOrigin);
            var assumeIdentityUrl = @json($assumeIdentityUrl);
            var assumeWindow = null;
            var assumeGrant = null;
            var assumeReadyNonce = null;
            var assumeGrantPosts = 0;

            function clearAssumeGrant() {
                assumeGrant = null;
            }

            function assumeReturnUrl() {
                return window.location.origin + '/admin/users';
            }

            function renderAssumeDebug(note) {
                var lines = [
                    'Marketplace: ' + assumeIdentityUrl,
                    'Expected popup origin: ' + assumeFrontendOrigin,
                    'Return address: ' + assumeReturnUrl(),
                    'Ready messages: ' + (assumeReadyNonce ? 'received' : 'none yet'),
                    'Grant posts: ' + assumeGrantPosts,
                    'Session: ' + (assumeSessionId || '(not started)')
                ];
                if (note) {
                    lines.push(note);
                }
                $('#assumeIdentityDebug').text(lines.join('\n'));
            }

            function deliverGrant() {
                if (!assumeWindow || assumeWindow.closed || !assumeReadyNonce || !assumeGrant || typeof assumeGrant.token !== 'string') {
                    return;
                }
                var sessionId = Number(assumeGrant.sessionId);
                if (!Number.isInteger(sessionId) || sessionId < 1) {
                    renderAssumeDebug('Session id from the server was not usable.');
                    return;
                }
                assumeWindow.postMessage({
                    type: 'psm-support-grant',
                    nonce: assumeReadyNonce,
                    access_token: assumeGrant.token,
                    session_id: sessionId,
                    return_url: assumeReturnUrl()
                }, assumeFrontendOrigin);
                assumeGrantPosts += 1;
                renderAssumeDebug('Grant posted to the marketplace window.');
            }

            window.addEventListener('message', function (event) {
                var data = event.data || {};
                if (data.type !== 'psm-support-ready' || typeof data.nonce !== 'string') return;
                if (!assumeFrontendOrigin || event.origin !== assumeFrontendOrigin) {
                    renderAssumeDebug('Ready message came from ' + event.origin + ', expected ' + assumeFrontendOrigin + '.');
                    return;
                }
                if (!assumeWindow || event.source !== assumeWindow) {
                    renderAssumeDebug('Ready message did not come from the marketplace window.');
                    return;
                }
                assumeReadyNonce = data.nonce;
                deliverGrant();
                if (!assumeGrant) {
                    renderAssumeDebug('Marketplace is ready. Waiting for the support session response.');
                }
            });

            $(document).on('click', '.js-assume-identity', function () {
                var button = $(this);
                assumeUrl = button.attr('data-url');
                assumeSessionId = null;
                var name = button.attr('data-user-name') || 'this user';
                var company = button.attr('data-company-name') || 'No company on file';
                $('#assumeIdentityPrompt').text('Start a read-only support session as ' + name + ' (' + company + ')?');
                $('#assumeIdentityAlert').hide().removeClass('alert-success alert-danger').text('');
                $('#assumeIdentityConfirm').show().prop('disabled', false);
                $('#assumeIdentityEnd').hide().prop('disabled', false);
                $('#assumeIdentityModal').modal('show');
            });

            $('#assumeIdentityConfirm').on('click', function () {
                if (!assumeUrl) {
                    return;
                }
                if (!assumeFrontendOrigin) {
                    $('#assumeIdentityAlert')
                        .removeClass('alert-success')
                        .addClass('alert-danger')
                        .text('The marketplace address is not configured.')
                        .show();
                    return;
                }
                var popup = window.open(assumeIdentityUrl, 'psmAssumeIdentity');
                if (!popup) {
                    $('#assumeIdentityAlert')
                        .removeClass('alert-success')
                        .addClass('alert-danger')
                        .text('Allow popups for this site, then start the session again.')
                        .show();
                    return;
                }
                assumeWindow = popup;
                clearAssumeGrant();
                assumeReadyNonce = null;
                assumeGrantPosts = 0;
                renderAssumeDebug('Waiting for the marketplace page.');
                var button = $(this);
                button.prop('disabled', true);
                $.ajax({
                    url: assumeUrl,
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': assumeCsrf
                    },
                    data: { _token: assumeCsrf },
                    success: function (response) {
                        var assumed = response.data && response.data.assumed_user ? response.data.assumed_user : {};
                        var when = response.data && response.data.expires_at ? response.data.expires_at : '';
                        var token = response.data ? response.data.access_token : null;
                        assumeSessionId = response.data ? response.data.session_id : null;
                        if (typeof token === 'string' && assumeSessionId) {
                            assumeGrant = { token: token, sessionId: assumeSessionId };
                            deliverGrant();
                        } else {
                            renderAssumeDebug('The server response did not include a session credential.');
                        }
                        $('#assumeIdentityAlert')
                            .removeClass('alert-danger')
                            .addClass('alert-success')
                            .text('Read-only support session started for ' + (assumed.name || 'the user') + ' at ' + (assumed.company || 'their company') + '. It expires at ' + when + '. The marketplace window is opening.')
                            .show();
                        button.hide();
                        if (assumeSessionId) {
                            $('#assumeIdentityEnd').show();
                        }
                    },
                    error: function (xhr) {
                        clearAssumeGrant();
                        if (assumeWindow) {
                            assumeWindow.close();
                            assumeWindow = null;
                        }
                        var message = 'Unable to start the support session.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        $('#assumeIdentityAlert')
                            .removeClass('alert-success')
                            .addClass('alert-danger')
                            .text(message)
                            .show();
                        button.prop('disabled', false);
                    }
                });
            });

            $('#assumeIdentityEnd').on('click', function () {
                if (!assumeSessionId) {
                    return;
                }
                var button = $(this);
                button.prop('disabled', true);
                $.ajax({
                    url: assumeEndTemplate.replace('__ID__', assumeSessionId),
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': assumeCsrf
                    },
                    data: { _token: assumeCsrf },
                    success: function () {
                        clearAssumeGrant();
                        assumeSessionId = null;
                        $('#assumeIdentityAlert')
                            .removeClass('alert-danger')
                            .addClass('alert-success')
                            .text('Support session ended. Your administrator sign-in is unchanged.')
                            .show();
                        button.hide();
                    },
                    error: function (xhr) {
                        var message = 'Unable to end the support session.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        $('#assumeIdentityAlert')
                            .removeClass('alert-success')
                            .addClass('alert-danger')
                            .text(message)
                            .show();
                        button.prop('disabled', false);
                    }
                });
            });
            @endif
        });
    </script>
@stop
