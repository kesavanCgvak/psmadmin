@extends('adminlte::page')

@section('title', 'Review Product Submission')

@section('content_header')
    <h1>Review Product Submission #{{ $submission->id }}</h1>
@stop

@section('css')
    @include('partials.responsive-css')
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

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Submitted Product</h3>
                    <div class="card-tools">
                        <span class="badge badge-{{ $submission->statusBadgeClass() }}">{{ $submission->status }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8"><strong>{{ $submission->name }}</strong></dd>
                        <dt class="col-sm-4">Description</dt>
                        <dd class="col-sm-8">{{ $submission->description ?: '—' }}</dd>
                        <dt class="col-sm-4">Suggested PSM Code</dt>
                        <dd class="col-sm-8">
                            @if($submission->psm_code)
                                <code>{{ $submission->psm_code }}</code>
                            @else
                                <span class="text-muted">Will be generated on approval</span>
                            @endif
                        </dd>
                        <dt class="col-sm-4">Replacement Price</dt>
                        <dd class="col-sm-8">{{ $submission->replacement_price !== null ? $submission->replacement_price : '—' }}</dd>
                        <dt class="col-sm-4">Brand</dt>
                        <dd class="col-sm-8">{{ $submission->brand?->name ?? '—' }}</dd>
                        <dt class="col-sm-4">Category</dt>
                        <dd class="col-sm-8">{{ $submission->category?->name ?? '—' }}</dd>
                        <dt class="col-sm-4">Sub-Category</dt>
                        <dd class="col-sm-8">{{ $submission->subCategory?->name ?? '—' }}</dd>
                        <dt class="col-sm-4">Webpage</dt>
                        <dd class="col-sm-8">
                            @if($submission->webpage_url)
                                <a href="{{ $submission->webpage_url }}" target="_blank" rel="noopener">{{ $submission->webpage_url }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Specifications</h3>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th>Height</th><td>{{ $submission->height ?? '—' }}</td></tr>
                        <tr><th>Width</th><td>{{ $submission->width ?? '—' }}</td></tr>
                        <tr><th>Length</th><td>{{ $submission->length ?? '—' }}</td></tr>
                        <tr><th>Weight</th><td>{{ $submission->weight ?? '—' }}</td></tr>
                        <tr><th>Linear Unit</th><td>{{ $submission->linearUnit?->code ?? '—' }}</td></tr>
                        <tr><th>Weight Unit</th><td>{{ $submission->weightUnit?->code ?? '—' }}</td></tr>
                        <tr><th>Country of Origin</th><td>{{ $submission->country_of_origin ?? '—' }}</td></tr>
                        <tr><th>ISO 2 / 3</th><td>{{ $submission->iso_code_2 ?? '—' }} / {{ $submission->iso_code_3 ?? '—' }}</td></tr>
                        <tr><th>HSN Code</th><td>{{ $submission->hsn_code ?? '—' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Submitter</h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">User</dt>
                        <dd class="col-sm-7">{{ $submission->submitter?->profile?->full_name ?: ($submission->submitter?->username ?? '—') }}</dd>
                        <dt class="col-sm-5">Email</dt>
                        <dd class="col-sm-7">{{ $submission->submitter?->profile?->email ?: ($submission->submitter?->email ?? '—') }}</dd>
                        <dt class="col-sm-5">Company</dt>
                        <dd class="col-sm-7">{{ $submission->company?->name ?? '—' }}</dd>
                        <dt class="col-sm-5">Submitted</dt>
                        <dd class="col-sm-7">{{ $submission->created_at?->format('M d, Y H:i') ?? '—' }}</dd>
                        @if($submission->reviewed_at)
                            <dt class="col-sm-5">Reviewed</dt>
                            <dd class="col-sm-7">
                                {{ $submission->reviewed_at->format('M d, Y H:i') }}
                                @if($submission->reviewer)
                                    by {{ $submission->reviewer->profile?->full_name ?: $submission->reviewer->username }}
                                @endif
                            </dd>
                        @endif
                        @if($submission->admin_notes)
                            <dt class="col-sm-5">Admin Notes</dt>
                            <dd class="col-sm-7">{{ $submission->admin_notes }}</dd>
                        @endif
                        @if($submission->inventoryMaster)
                            <dt class="col-sm-5">Inventory</dt>
                            <dd class="col-sm-7">
                                <a href="{{ route('admin.products.show', $submission->inventoryMaster) }}">
                                    {{ $submission->inventoryMaster->model }} ({{ $submission->inventoryMaster->psm_code }})
                                </a>
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Images</h3>
        </div>
        <div class="card-body">
            @if($submission->images->isEmpty())
                <p class="text-muted mb-0">No images submitted.</p>
            @else
                <div class="row">
                    @foreach($submission->images as $image)
                        <div class="col-md-3 mb-3">
                            <img src="{{ \App\Support\InventoryImageManagementService::publicUrl($image->image_path) }}"
                                 alt="Submission image"
                                 class="img-fluid img-thumbnail">
                            @if($image->is_primary)
                                <span class="badge badge-primary mt-1">Primary</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if($submission->isPending())
        <div class="card card-warning">
            <div class="card-header">
                <h3 class="card-title">Approve into inventory_master</h3>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.psm-product-submissions.approve', $submission) }}" method="POST">
                    @csrf
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="name">Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $submission->name) }}" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="psm_code">PSM Code (leave blank to auto-generate)</label>
                                <input type="text" name="psm_code" id="psm_code" class="form-control" value="{{ old('psm_code', $submission->psm_code) }}">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="brand_id">Brand</label>
                                <select name="brand_id" id="brand_id" class="form-control">
                                    <option value="">—</option>
                                    @foreach($brands as $brand)
                                        <option value="{{ $brand->id }}" @selected(old('brand_id', $submission->brand_id) == $brand->id)>{{ $brand->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="category_id">Category</label>
                                <select name="category_id" id="category_id" class="form-control">
                                    <option value="">—</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" @selected(old('category_id', $submission->category_id) == $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="replacement_price">Replacement Price</label>
                                <input type="number" step="0.01" min="0" name="replacement_price" id="replacement_price" class="form-control" value="{{ old('replacement_price', $submission->replacement_price) }}">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="length">Length</label>
                                <input type="number" step="0.01" min="0" name="length" id="length" class="form-control" value="{{ old('length', $submission->length) }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="width">Width</label>
                                <input type="number" step="0.01" min="0" name="width" id="width" class="form-control" value="{{ old('width', $submission->width) }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="height">Height</label>
                                <input type="number" step="0.01" min="0" name="height" id="height" class="form-control" value="{{ old('height', $submission->height) }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="linear_unit_id">Linear Unit</label>
                                <select name="linear_unit_id" id="linear_unit_id" class="form-control">
                                    <option value="">—</option>
                                    @foreach($linearUnits as $unit)
                                        <option value="{{ $unit->id }}" @selected(old('linear_unit_id', $submission->linear_unit_id) == $unit->id)>{{ $unit->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="weight">Weight</label>
                                <input type="number" step="0.01" min="0" name="weight" id="weight" class="form-control" value="{{ old('weight', $submission->weight) }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="weight_unit_id">Weight Unit</label>
                                <select name="weight_unit_id" id="weight_unit_id" class="form-control">
                                    <option value="">—</option>
                                    @foreach($weightUnits as $unit)
                                        <option value="{{ $unit->id }}" @selected(old('weight_unit_id', $submission->weight_unit_id) == $unit->id)>{{ $unit->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="country_of_origin">Country of Origin</label>
                                <input type="text" name="country_of_origin" id="country_of_origin" class="form-control" value="{{ old('country_of_origin', $submission->country_of_origin) }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="hsn_code">HSN Code</label>
                                <input type="text" name="hsn_code" id="hsn_code" class="form-control" value="{{ old('hsn_code', $submission->hsn_code) }}">
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="webpage_url" value="{{ old('webpage_url', $submission->webpage_url) }}">
                    <input type="hidden" name="sub_category_id" value="{{ old('sub_category_id', $submission->sub_category_id) }}">
                    <input type="hidden" name="iso_code_2" value="{{ old('iso_code_2', $submission->iso_code_2) }}">
                    <input type="hidden" name="iso_code_3" value="{{ old('iso_code_3', $submission->iso_code_3) }}">
                    <div class="form-group">
                        <label for="approve_admin_notes">Admin Notes (optional)</label>
                        <textarea name="admin_notes" id="approve_admin_notes" class="form-control" rows="2">{{ old('admin_notes') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-success" onclick="return confirm('Approve this product into the PSM inventory catalog?');">
                        <i class="fas fa-check"></i> Approve
                    </button>
                </form>

                <hr>

                <form action="{{ route('admin.psm-product-submissions.reject', $submission) }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <label for="reject_admin_notes">Rejection Reason (optional)</label>
                        <textarea name="admin_notes" id="reject_admin_notes" class="form-control" rows="2">{{ old('admin_notes') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this submission? No inventory_master record will be created.');">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </form>
            </div>
        </div>
    @endif

    <a href="{{ route('admin.psm-product-submissions.index') }}" class="btn btn-default">
        <i class="fas fa-arrow-left"></i> Back to list
    </a>
@stop
