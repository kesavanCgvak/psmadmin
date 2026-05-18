@extends('adminlte::page')

@section('title', 'Company Details')

@php
    $fromUsersListing = request()->query('from') === 'users';
    $backToUsersParams = [];
    if (request()->filled('page')) {
        $backToUsersParams['page'] = request()->query('page');
    }
    if (request()->filled('search')) {
        $backToUsersParams['search'] = request()->query('search');
    }
    $backToUsersUrl = route('admin.users.index', $backToUsersParams);
@endphp

@section('content_header')
    <h1 class="m-0">Company Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <!-- Company Information -->
            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">{{ $company->name }}</h3>
                </div>
                <div class="card-body">
                    <div class="mb-3 d-flex flex-wrap align-items-center">
                        @php
                            $companyType = strtolower((string) $company->account_type);
                        @endphp
                        @if($companyType === 'provider')
                            <span class="badge badge-primary mr-2">Provider Company</span>
                        @elseif($companyType === 'user')
                            <span class="badge badge-secondary mr-2">User Company</span>
                        @else
                            <span class="badge badge-light mr-2">Type Not Set</span>
                        @endif

                        @if($company->subscription_mode === 'free')
                            <span class="badge badge-secondary mr-2">Free Subscription</span>
                        @else
                            <span class="badge badge-success mr-2">Paid Subscription</span>
                        @endif

                        @if($companyType === 'provider')
                            @if((bool) ($company->is_open_api_enabled ?? false))
                                <span class="badge badge-success mr-2">Open API Enabled</span>
                            @else
                                <span class="badge badge-secondary mr-2">Open API Disabled</span>
                            @endif
                        @endif

                        @if($company->currency)
                            <span class="badge badge-success">{{ $company->currency->code }}</span>
                        @endif
                    </div>

                    <dl class="row">
                        <dt class="col-sm-3">ID</dt>
                        <dd class="col-sm-9">{{ $company->id }}</dd>

                        <dt class="col-sm-3">Name</dt>
                        <dd class="col-sm-9"><strong>{{ $company->name }}</strong></dd>

                        <dt class="col-sm-3">Account Type</dt>
                        <dd class="col-sm-9">
                            @if($companyType === 'provider')
                                <span class="badge badge-primary">Provider</span>
                            @elseif($companyType === 'user')
                                <span class="badge badge-secondary">User</span>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9">{{ $company->description ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Location</dt>
                        <dd class="col-sm-9">
                            @if($company->region)
                                <span class="badge badge-primary">{{ $company->region->name }}</span>
                            @endif
                            @if($company->country)
                                <span class="badge badge-success">{{ $company->country->name }}</span>
                            @endif
                            @if($company->state)
                                <span class="badge badge-info">{{ $company->state->name }}</span>
                            @endif
                            @if($company->city)
                                <span class="badge badge-warning">{{ $company->city->name }}</span>
                            @endif
                            @if(!$company->region && !$company->country)
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Address</dt>
                        <dd class="col-sm-9">
                            @if($company->address_line_1)
                                {{ $company->address_line_1 }}<br>
                            @endif
                            @if($company->address_line_2)
                                {{ $company->address_line_2 }}<br>
                            @endif
                            @if($company->postal_code)
                                {{ $company->postal_code }}
                            @endif
                            @if(!$company->address_line_1 && !$company->postal_code)
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">GPS Coordinates</dt>
                        <dd class="col-sm-9">
                            @if($company->latitude && $company->longitude)
                                {{ number_format($company->latitude, 6) }}, {{ number_format($company->longitude, 6) }}
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Currency</dt>
                        <dd class="col-sm-9">
                            @if($company->currency)
                                <span class="badge badge-success">{{ $company->currency->code }}</span> {{ $company->currency->name }}
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Rental Software</dt>
                        <dd class="col-sm-9">
                            @if($company->rentalSoftware)
                                <span class="badge badge-info">{{ $company->rentalSoftware->name }}</span>
                                @if($company->rentalSoftware->version)
                                    <small class="text-muted">v{{ $company->rentalSoftware->version }}</small>
                                @endif
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3">Date Format</dt>
                        <dd class="col-sm-9">{{ $company->date_format ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Pricing Scheme</dt>
                        <dd class="col-sm-9">{{ $company->pricing_scheme ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Subscription Mode</dt>
                        <dd class="col-sm-9">
                            @if($company->subscription_mode === 'free')
                                <span class="badge badge-secondary">Free</span>
                            @else
                                <span class="badge badge-success">Paid</span>
                            @endif
                        </dd>

                        @if($companyType === 'provider')
                            <dt class="col-sm-3">Open API Access</dt>
                            <dd class="col-sm-9">
                                @if((bool) ($company->is_open_api_enabled ?? false))
                                    <span class="badge badge-success">Enabled</span>
                                @else
                                    <span class="badge badge-secondary">Disabled</span>
                                @endif
                                <div class="small text-muted mt-1">Partner API keys work only when enabled here and the company type is Provider.</div>
                            </dd>
                        @else
                            <dt class="col-sm-3">Open API Access</dt>
                            <dd class="col-sm-9"><span class="text-muted">Not applicable (User companies do not use partner Open API).</span></dd>
                        @endif

                        <dt class="col-sm-3">Search Priority</dt>
                        <dd class="col-sm-9">{{ $company->search_priority ?? 'N/A' }}</dd>

                        <dt class="col-sm-3">Rating</dt>
                        <dd class="col-sm-9">
                            @php
                                $rating = $displayRating ?? 0;
                                $fullStars = (int) floor($rating);
                                $hasHalfStar = ($rating - $fullStars) >= 0.5 && $fullStars < 5;
                            @endphp
                            @for($i = 1; $i <= 5; $i++)
                                @if($i <= $fullStars)
                                    <i class="fas fa-star text-warning"></i>
                                @elseif($hasHalfStar && $i === $fullStars + 1)
                                    <i class="fas fa-star-half-alt text-warning"></i>
                                @else
                                    <i class="far fa-star text-muted"></i>
                                @endif
                            @endfor
                            ({{ number_format((float) $rating, 1) }})
                            @if(($overrideRating ?? null) !== null)
                                <span class="badge badge-dark ml-1">Override</span>
                            @endif
                            <div>
                                <small class="text-muted">
                                    User avg: {{ number_format((float) ($userAvg ?? 0), 1) }}
                                    ({{ (int) ($userCount ?? 0) }} users)
                                </small>
                            </div>
                        </dd>

                        <dt class="col-sm-3">Created At</dt>
                        <dd class="col-sm-9">{{ $company->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-3">Updated At</dt>
                        <dd class="col-sm-9">{{ $company->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.companies.edit', $company) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    @if($fromUsersListing)
                        <a href="{{ $backToUsersUrl }}" class="btn btn-primary btn-lg">
                            <i class="fas fa-arrow-left"></i> Back to Users
                        </a>
                    @else
                        <a href="{{ route('admin.companies.index') }}" class="btn btn-default">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Statistics -->
            <div class="card card-widget widget-user">
                <div class="widget-user-header bg-info">
                    <h3 class="widget-user-username">{{ $company->name }}</h3>
                    <h5 class="widget-user-desc">
                        {{ $companyType === 'provider' ? 'Provider' : ($companyType === 'user' ? 'User' : 'Company') }} Statistics
                    </h5>
                </div>
                <div class="widget-user-image">
                    <img class="img-circle elevation-2" src="{{ $company->logo ? asset($company->logo) : asset('vendor/adminlte/dist/img/AdminLTELogo.png') }}" alt="Company Logo">
                </div>
                <div class="card-footer">
                    <div class="row">
                        <div class="col-sm-6 border-right">
                            <div class="description-block">
                                <h5 class="description-header">{{ $company->users->count() }}</h5>
                                <span class="description-text">USERS</span>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="description-block">
                                <h5 class="description-header" id="companyEquipmentCount">{{ $company->equipments_count }}</h5>
                                <span class="description-text">EQUIPMENT</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Users List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Company Users</h3>
                </div>
                <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                    @if($company->users->count() > 0)
                        <ul class="list-group list-group-flush">
                            @foreach($company->users as $user)
                                <li class="list-group-item">
                                    <a href="{{ route('admin.users.show', $user) }}?{{ http_build_query(['from' => 'company', 'company_id' => $company->id]) }}">
                                        <strong>{{ $user->username }}</strong>
                                    </a>
                                    @if($user->is_admin)
                                        <span class="badge badge-success float-right">Admin</span>
                                    @else
                                        <span class="badge badge-info float-right">User</span>
                                    @endif
                                    <br>
                                    <small class="text-muted">{{ $user->profile?->email ?? 'No email' }}</small>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted p-3">No users yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Marketplace inventory (company_inventory ↔ inventory_master) -->
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
                    <h3 class="card-title mb-2 mb-md-0">Marketplace inventory</h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addCompanyInventoryModal">
                            <i class="fas fa-plus"></i> Add product from catalog
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Products listed here are available in this company’s marketplace (<code>company_inventory</code> linked to <code>inventory_master</code>).
                        Use search to find rows; add or remove links without leaving this page.
                    </p>
                    <table id="companyInventoryTable" class="table table-bordered table-striped table-sm" style="width:100%">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Model</th>
                                <th>Brand</th>
                                <th>PSM Code</th>
                                <th>Qty</th>
                                <th>Rental price</th>
                                <th>Software code</th>
                                <th style="width:90px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add product from inventory_master -->
    <div class="modal fade" id="addCompanyInventoryModal" tabindex="-1" role="dialog" aria-labelledby="addCompanyInventoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCompanyInventoryModalLabel">Add product to company inventory</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="inventoryMasterSearch">Search catalog (model, PSM code, brand)</label>
                        <input type="text" class="form-control" id="inventoryMasterSearch" placeholder="Type at least 2 characters…" autocomplete="off">
                    </div>
                    <input type="hidden" id="selectedProductId" value="">
                    <div id="inventoryMasterSearchHint" class="small text-muted mb-2">Only products not already linked to this company are shown.</div>
                    <div id="inventoryMasterSearchResults" class="list-group mb-3" style="max-height: 300px; overflow-y: auto;"></div>
                    <div id="selectedProductSummary" class="alert alert-secondary py-2 d-none" role="alert"></div>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="addInvQuantity">Quantity</label>
                            <input type="number" class="form-control" id="addInvQuantity" value="1" min="1">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="addInvRental">Rental price (optional)</label>
                            <input type="number" class="form-control" id="addInvRental" min="0" step="0.01" placeholder="Leave blank for unset">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="addInvSoftware">Software code (optional)</label>
                            <input type="text" class="form-control" id="addInvSoftware" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmAddInventoryBtn" disabled>
                        <i class="fas fa-link"></i> Link product
                    </button>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(function () {
            $.ajaxSetup({
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
            });

            var inventoryTable = initResponsiveDataTable('companyInventoryTable', {
                processing: true,
                serverSide: true,
                stateSave: false,
                order: [[0, 'desc']],
                ajax: {
                    url: "{{ route('admin.companies.inventory.data', $company) }}",
                    type: 'GET',
                    error: function () {
                        alert('Could not load marketplace inventory. Please refresh.');
                    }
                },
                columns: [
                    { data: 'id', name: 'id' },
                    {
                        data: 'model',
                        name: 'model',
                        render: function (data, type, row) {
                            if (row.product_id) {
                                return '<a href="{{ url('/admin/products') }}/' + row.product_id + '">' + $('<div/>').text(data).html() + '</a>';
                            }
                            return $('<div/>').text(data).html();
                        }
                    },
                    {
                        data: 'brand',
                        name: 'brand',
                        render: function (data) {
                            return '<span class="badge badge-success">' + $('<div/>').text(data).html() + '</span>';
                        }
                    },
                    { data: 'psm_code', name: 'psm_code' },
                    {
                        data: 'quantity',
                        name: 'quantity',
                        render: function (data) {
                            return '<span class="badge badge-primary">' + data + '</span>';
                        }
                    },
                    { data: 'rental_price', name: 'rental_price', orderable: false },
                    { data: 'software_code', name: 'software_code', orderable: false },
                    { data: 'actions', name: 'actions', orderable: false, searchable: false }
                ]
            });

            $('#companyInventoryTable').on('click', '.btn-remove-inventory', function () {
                var url = $(this).data('url');
                if (!url || !confirm('Remove this product from the company’s marketplace inventory?')) {
                    return;
                }
                $.ajax({
                    url: url,
                    type: 'DELETE',
                    success: function (res) {
                        if (res.success) {
                            inventoryTable.ajax.reload(null, false);
                            var n = parseInt($('#companyEquipmentCount').text(), 10);
                            if (!isNaN(n) && n > 0) {
                                $('#companyEquipmentCount').text(n - 1);
                            }
                        } else {
                            alert(res.message || 'Remove failed.');
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Remove failed.';
                        alert(msg);
                    }
                });
            });

            var searchTimer = null;
            window.resetAddInventoryModal = function () {
                $('#inventoryMasterSearch').val('');
                $('#selectedProductId').val('');
                $('#inventoryMasterSearchResults').empty();
                $('#selectedProductSummary').addClass('d-none').text('');
                $('#addInvQuantity').val('1');
                $('#addInvRental').val('');
                $('#addInvSoftware').val('');
                $('#confirmAddInventoryBtn').prop('disabled', true);
            };

            $('#addCompanyInventoryModal').on('hidden.bs.modal', function () {
                resetAddInventoryModal();
            });

            $('#inventoryMasterSearch').on('input', function () {
                var q = $(this).val().trim();
                clearTimeout(searchTimer);
                var $results = $('#inventoryMasterSearchResults');
                if (q.length < 2) {
                    $results.empty();
                    return;
                }
                searchTimer = setTimeout(function () {
                    $.get("{{ route('admin.companies.inventory.search-master', $company) }}", { search: q, exclude_linked: 1 }, function (items) {
                        $results.empty();
                        if (!items.length) {
                            $results.append('<div class="list-group-item text-muted">No products found (or all matches are already linked).</div>');
                            return;
                        }
                        items.forEach(function (p) {
                            var $item = $('<a href="#" class="list-group-item list-group-item-action"></a>');
                            $item.append(
                                $('<div class="d-flex w-100 justify-content-between"></div>')
                                    .append($('<strong></strong>').text(p.model))
                                    .append($('<small class="text-muted"></small>').text(p.psm_code))
                            );
                            $item.append($('<small></small>').text((p.brand || '') + ' · ' + (p.category || '')));
                            $item.data('product', p);
                            $item.on('click', function (e) {
                                e.preventDefault();
                                $('#selectedProductId').val(p.id);
                                $('#selectedProductSummary')
                                    .removeClass('d-none')
                                    .text('Selected: ' + p.model + ' (' + p.psm_code + ') — ' + p.brand);
                                $('#confirmAddInventoryBtn').prop('disabled', false);
                                $results.find('.list-group-item').removeClass('active');
                                $item.addClass('active');
                            });
                            $results.append($item);
                        });
                    });
                }, 300);
            });

            $('#confirmAddInventoryBtn').on('click', function () {
                var productId = $('#selectedProductId').val();
                if (!productId) {
                    return;
                }
                var payload = {
                    product_id: productId,
                    quantity: $('#addInvQuantity').val() || 1,
                    rental_price: $('#addInvRental').val() === '' ? null : $('#addInvRental').val(),
                    software_code: $('#addInvSoftware').val() || null
                };
                $.ajax({
                    url: "{{ route('admin.companies.inventory.store', $company) }}",
                    type: 'POST',
                    data: payload,
                    success: function (res) {
                        if (res.success) {
                            $('#addCompanyInventoryModal').modal('hide');
                            inventoryTable.ajax.reload(null, false);
                            var n = parseInt($('#companyEquipmentCount').text(), 10);
                            if (!isNaN(n)) {
                                $('#companyEquipmentCount').text(n + 1);
                            }
                        } else {
                            alert(res.message || 'Could not add product.');
                        }
                    },
                    error: function (xhr) {
                        var msg = 'Could not add product.';
                        if (xhr.responseJSON) {
                            if (xhr.responseJSON.message) msg = xhr.responseJSON.message;
                            else if (xhr.responseJSON.errors) {
                                msg = Object.values(xhr.responseJSON.errors).flat().join(' ');
                            }
                        }
                        alert(msg);
                    }
                });
            });
        });
    </script>
@stop

