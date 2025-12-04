@extends('adminlte::page')

@section('title', 'Super Admin Users')

@section('content_header')
    <h1>Super Admin User Management</h1>
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
            <h3 class="card-title">All Super Admin Users</h3>
            <div class="card-tools">
                @if($isSuperAdmin)
                    <a href="{{ route('admin.admin-users.create') }}" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> Add New Super Admin
                    </a>
                @endif
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="adminUsersTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Mobile</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($adminUsers as $admin)
                            <tr class="{{ $admin->is_blocked ? 'table-danger' : '' }}">
                                <td>{{ $admin->id }}</td>
                                <td>
                                    <strong>{{ $admin->username }}</strong>
                                    @if($admin->profile?->email === 'kesavan@cgvak.com' || $admin->email === 'kesavan@cgvak.com')
                                        <span class="badge badge-warning ml-1">Primary</span>
                                    @endif
                                </td>
                                <td>{{ $admin->profile?->full_name ?? 'N/A' }}</td>
                                <td>{{ $admin->profile?->email ?? $admin->email ?? 'N/A' }}</td>
                                <td>{{ $admin->profile?->mobile ?? 'N/A' }}</td>
                                <td>
                                    @php
                                        $roleColors = [
                                            'super_admin' => 'danger',
                                            'admin' => 'primary',
                                        ];
                                        $roleColor = $roleColors[$admin->role] ?? 'secondary';
                                    @endphp
                                    <span class="badge badge-{{ $roleColor }}">
                                        {{ ucfirst(str_replace('_', ' ', $admin->role)) }}
                                    </span>
                                </td>
                                <td>
                                    @if($admin->is_blocked)
                                        <span class="badge badge-danger">Blocked</span>
                                    @else
                                        <span class="badge badge-success">Active</span>
                                    @endif
                                    @if($admin->email_verified)
                                        <span class="badge badge-info">Verified</span>
                                    @endif
                                </td>
                                <td>{{ $admin->created_at?->format('M d, Y') }}</td>
                                <td>
                                    <div class="btn-group">
                                        <a href="{{ route('admin.admin-users.show', $admin) }}" class="btn btn-info btn-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        @if($isSuperAdmin)
                                            <a href="{{ route('admin.admin-users.edit', $admin) }}" class="btn btn-warning btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            @if($admin->is_blocked)
                                                <form action="{{ route('admin.admin-users.reactivate', $admin) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-success btn-sm" title="Reactivate">
                                                        <i class="fas fa-check-circle"></i>
                                                    </button>
                                                </form>
                                            @else
                                                @if($admin->profile?->email !== 'kesavan@cgvak.com' && $admin->email !== 'kesavan@cgvak.com' && $admin->id !== auth()->id())
                                                    <form action="{{ route('admin.admin-users.destroy', $admin) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to deactivate this admin user?');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-danger btn-sm" title="Deactivate">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if(!$isSuperAdmin)
        <div class="alert alert-info">
            <i class="icon fas fa-info-circle"></i>
            <strong>Note:</strong> You have view-only access to Super Admin users. Only the Super Admin (kesavan@cgvak.com) can create, edit, or delete Super Admin users.
        </div>
    @endif
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            initResponsiveDataTable('adminUsersTable', {
                "columnDefs": [
                    { "orderable": false, "targets": [8] },
                    { "responsivePriority": 1, "targets": 1 },
                    { "responsivePriority": 2, "targets": 8 }
                ],
                "order": [[0, "desc"]]
            });
        });
    </script>
@stop

