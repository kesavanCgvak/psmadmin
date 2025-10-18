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
                                    <td>{{ $user->id }}</td>
                                    <td>
                                        <div class="profile-picture-container">
                                            @if($user->profile?->profile_picture && file_exists(public_path('storage/' . $user->profile->profile_picture)))
                                                <img src="{{ asset('storage/' . $user->profile->profile_picture) }}"
                                                     alt="{{ $user->username }}"
                                                     class="profile-picture"
                                                     style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%; border: 2px solid #dee2e6;"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            @endif
                                            <div class="profile-initial {{ $user->profile?->profile_picture && file_exists(public_path('storage/' . $user->profile->profile_picture)) ? 'd-none' : 'd-flex' }} align-items-center justify-content-center"
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
                                        @if($user->company?->name)
                                            {{ $user->company->name }}
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
@stop

@section('css')
    @include('partials.responsive-css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.3.0/css/responsive.bootstrap4.min.css">
    <style>
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
                        "targets": [1, 10]
                    },
                    {
                        "searchable": false,
                        "targets": [1, 10]
                    },
                    {
                        "responsivePriority": 1,
                        "targets": 2 // Username - always visible
                    },
                    {
                        "responsivePriority": 2,
                        "targets": 10 // Actions - always visible
                    },
                    {
                        "responsivePriority": 3,
                        "targets": [5, 6] // Email and Account Type
                    }
                ],
                "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                       '<"row"<"col-sm-12"tr>>' +
                       '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                "pagingType": "simple_numbers",
                "drawCallback": function() {
                    // Ensure buttons are properly aligned
                    $('.btn-group').each(function() {
                        $(this).css('display', 'flex');
                    });
                }
            });

            // Add responsive behavior for window resize
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
        });
    </script>
@stop
