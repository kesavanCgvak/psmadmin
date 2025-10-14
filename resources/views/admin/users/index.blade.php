@extends('adminlte::page')

@section('title', 'User Management')

@section('content_header')
    <h1>User Management</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Users List</h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add New User
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap4.min.css">
    <style>
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_processing,
        .dataTables_wrapper .dataTables_paginate {
            color: #333;
            margin-bottom: 10px;
        }
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
            text-decoration: none;
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
        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
            color: #6c757d !important;
            background-color: #fff;
            border-color: #dee2e6;
        }
        .dataTables_wrapper .dataTables_length select {
            padding: 0.25rem 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
        }
        .dataTables_wrapper .dataTables_filter input {
            padding: 0.375rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
        }

        /* Table styling improvements */
        #users-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100% !important;
            table-layout: fixed;
        }

        /* Fix table container width */
        .dataTables_wrapper {
            overflow-x: auto;
            width: 100%;
        }

        .card-body {
            overflow-x: auto;
            padding: 1rem;
        }

        /* Ensure table fits within container */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        #users-table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            padding: 12px 8px;
        }

        #users-table tbody tr {
            transition: background-color 0.15s ease-in-out;
        }

        #users-table tbody tr:hover {
            background-color: #f8f9fa;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        #users-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        #users-table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        #users-table tbody td {
            padding: 10px 8px;
            vertical-align: middle;
            border-bottom: 1px solid #dee2e6;
        }

        /* Profile picture styling */
        .profile-picture-container {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            margin: 0 auto;
        }

        .profile-picture {
            border: 2px solid #dee2e6;
            transition: border-color 0.15s ease-in-out;
        }

        .profile-picture:hover {
            border-color: #007bff;
        }

        .profile-initial {
            transition: background-color 0.15s ease-in-out;
        }

        .profile-initial:hover {
            background-color: #0056b3 !important;
        }

        /* Badge improvements */
        .badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        /* Action buttons styling */
        .btn-group .btn-sm {
            padding: 0.25rem 0.5rem;
            margin: 0 1px;
        }

        /* Column width controls */
        #users-table th:nth-child(1), #users-table td:nth-child(1) { width: 5%; } /* ID */
        #users-table th:nth-child(2), #users-table td:nth-child(2) { width: 8%; } /* Profile Picture */
        #users-table th:nth-child(3), #users-table td:nth-child(3) { width: 12%; } /* Username */
        #users-table th:nth-child(4), #users-table td:nth-child(4) { width: 12%; } /* Full Name */
        #users-table th:nth-child(5), #users-table td:nth-child(5) { width: 18%; } /* Email */
        #users-table th:nth-child(6), #users-table td:nth-child(6) { width: 8%; } /* Account Type */
        #users-table th:nth-child(7), #users-table td:nth-child(7) { width: 8%; } /* Role */
        #users-table th:nth-child(8), #users-table td:nth-child(8) { width: 12%; } /* Company */
        #users-table th:nth-child(9), #users-table td:nth-child(9) { width: 8%; } /* Email Status */
        #users-table th:nth-child(10), #users-table td:nth-child(10) { width: 9%; } /* Member Since */
        #users-table th:nth-child(11), #users-table td:nth-child(11) { width: 10%; } /* Actions */

        /* Actions column specific styling */
        #users-table th:nth-child(11), #users-table td:nth-child(11) {
            text-align: center;
            white-space: nowrap;
        }

        /* Text styling improvements */
        .text-muted {
            color: #6c757d !important;
            font-style: italic;
        }

        /* Better spacing for table content */
        #users-table tbody td {
            line-height: 1.4;
        }

        /* Improve badge readability */
        .badge {
            font-weight: 500;
            letter-spacing: 0.025em;
        }

        /* Responsive improvements */
        @media (max-width: 768px) {
            #users-table {
                font-size: 0.875rem;
            }

            #users-table thead th,
            #users-table tbody td {
                padding: 8px 4px;
            }

            .badge {
                font-size: 0.7rem;
                padding: 0.2rem 0.4rem;
            }
        }
    </style>
@stop

@section('js')
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap4.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#users-table').DataTable({
                "responsive": false,
                "lengthChange": true,
                "autoWidth": false,
                "scrollX": true,
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                "order": [[ 0, "desc" ]],
                "language": {
                    "search": "Search users:",
                    "lengthMenu": "Show _MENU_ users",
                    "info": "Showing _START_ to _END_ of _TOTAL_ users",
                    "infoEmpty": "No users available",
                    "infoFiltered": "(filtered from _MAX_ total users)",
                    "zeroRecords": "No matching users found"
                },
                "columnDefs": [
                    { "orderable": false, "targets": [1, 10] }, // Disable sorting on profile picture and actions columns
                    { "searchable": false, "targets": [1, 10] } // Disable search on profile picture and actions columns
                ],
                "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                       '<"row"<"col-sm-12"tr>>' +
                       '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                "pagingType": "full_numbers"
            });
        });
    </script>
@stop
