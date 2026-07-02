@extends('adminlte::page')

@section('title', 'Logo Management')

@section('content_header')
    <h1>Logo Management</h1>
@stop

@section('css')
    @include('partials.responsive-css')
    <link rel="stylesheet" href="{{ asset('common/css/logo-management.css') }}">
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
            <h3 class="card-title">Promotional Logo Consent</h3>
            <div class="card-tools">
                <span class="badge badge-primary">{{ $companies->total() }} Companies</span>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                Companies listed below have allowed their logo for promotional use. Admin approval controls whether the logo is published; user consent is managed by the company.
            </p>

            @if($companies->isEmpty())
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle"></i> No companies have opted in to promotional logo use yet.
                </div>
            @else
                <div class="table-responsive">
                    <table id="logoManagementTable" class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Logo</th>
                                <th>Company</th>
                                <th>Account Type</th>
                                <th>User Consent</th>
                                <th>Admin Status</th>
                                <th>Consent Date</th>
                                <th class="logo-management-actions-cell">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($companies as $company)
                                @php
                                    $companyType = strtolower((string) $company->account_type);
                                @endphp
                                <tr>
                                    <td>
                                        <img src="{{ asset($company->logo) }}"
                                             alt="{{ $company->name }} logo"
                                             class="img-thumbnail logo-management-thumb">
                                    </td>
                                    <td>
                                        <strong>{{ $company->name }}</strong>
                                        <div class="small text-muted">ID: {{ $company->id }}</div>
                                        @if($company->isLogoPromotionActive())
                                            <span class="badge badge-success mt-1">Live for promotion</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($companyType === 'provider')
                                            <span class="badge badge-primary">Provider</span>
                                        @elseif($companyType === 'user')
                                            <span class="badge badge-secondary">User</span>
                                        @else
                                            <span class="badge badge-light">N/A</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if((bool) $company->logo_available_for_promotion)
                                            <span class="badge badge-success">Enabled</span>
                                        @else
                                            <span class="badge badge-secondary">Disabled</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if((bool) $company->logo_promotion_admin_enabled)
                                            <span class="badge badge-success">Enabled</span>
                                        @else
                                            <span class="badge badge-warning">Disabled</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $company->logo_promotion_consent_at?->format('M d, Y H:i') ?? 'N/A' }}
                                    </td>
                                    <td class="logo-management-actions-cell">
                                        <div class="logo-management-actions">
                                            <a href="{{ route('admin.companies.show', $company) }}"
                                               class="btn btn-info btn-sm"
                                               title="View Company">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form action="{{ route('admin.logo-management.update-admin-status', $company) }}"
                                                  method="POST"
                                                  class="logo-admin-status-form"
                                                  data-company-name="{{ $company->name }}"
                                                  data-enabling="{{ (bool) $company->logo_promotion_admin_enabled ? '0' : '1' }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="logo_promotion_admin_enabled" value="{{ (bool) $company->logo_promotion_admin_enabled ? '0' : '1' }}">
                                                <button type="submit"
                                                        class="btn btn-sm {{ (bool) $company->logo_promotion_admin_enabled ? 'btn-warning' : 'btn-success' }}"
                                                        title="{{ (bool) $company->logo_promotion_admin_enabled ? 'Disable admin approval' : 'Enable admin approval' }}">
                                                    <i class="fas fa-{{ (bool) $company->logo_promotion_admin_enabled ? 'toggle-off' : 'toggle-on' }}"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    {{ $companies->links() }}
                </div>
            @endif
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            if ($('#logoManagementTable tbody tr').length) {
                initResponsiveDataTable('logoManagementTable', {
                    "paging": false,
                    "info": false,
                    "columnDefs": [
                        { "orderable": false, "targets": [0, -1] },
                        { "width": "110px", "targets": -1 },
                        { "responsivePriority": 1, "targets": 1 },
                        { "responsivePriority": 2, "targets": -1 }
                    ],
                    "order": [[5, "desc"]]
                });
            }

            $(document).on('submit', '.logo-admin-status-form', function(e) {
                if (!$(this).data('confirmed')) {
                    e.preventDefault();
                    var companyName = $(this).data('company-name');
                    var enabling = $(this).data('enabling') === 1 || $(this).data('enabling') === '1';
                    var action = enabling ? 'enable admin approval for' : 'disable admin approval for';
                    if (confirm('Are you sure you want to ' + action + ' ' + companyName + '?')) {
                        $(this).data('confirmed', true);
                        $(this).trigger('submit');
                    }
                }
            });
        });
    </script>
@stop
