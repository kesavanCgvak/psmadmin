@extends('adminlte::page')

@section('title', 'Review AI Specification')

@section('content_header')
    <h1>Review AI Specification #{{ $aiSpec->id }}</h1>
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
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Product Summary</h3>
                    <div class="card-tools">
                        <span class="badge badge-{{ \App\Support\InventoryAiSpecPresenter::statusBadgeClass($aiSpec->status) }}">
                            {{ str_replace('_', ' ', $aiSpec->status) }}
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Product</dt>
                        <dd class="col-sm-9">
                            <a href="{{ route('admin.products.show', $aiSpec->product) }}">{{ $aiSpec->product->model }}</a>
                            (ID {{ $aiSpec->product->id }})
                        </dd>
                        <dt class="col-sm-3">Manufacturer</dt>
                        <dd class="col-sm-9">{{ $aiSpec->product->brand?->name ?? '—' }}</dd>
                        <dt class="col-sm-3">Category</dt>
                        <dd class="col-sm-9">{{ $aiSpec->product->category?->name ?? '—' }}</dd>
                        <dt class="col-sm-3">Confidence Score</dt>
                        <dd class="col-sm-9">{{ $aiSpec->confidence_score ?? '—' }}</dd>
                        <dt class="col-sm-3">Source URL</dt>
                        <dd class="col-sm-9">
                            @if($aiSpec->source_url)
                                <a href="{{ $aiSpec->source_url }}" target="_blank" rel="noopener">{{ $aiSpec->source_url }}</a>
                            @else
                                —
                            @endif
                        </dd>
                        @if($aiSpec->reviewed_at)
                            <dt class="col-sm-3">Reviewed</dt>
                            <dd class="col-sm-9">
                                {{ $aiSpec->reviewed_at->format('M d, Y H:i') }}
                                @if($aiSpec->reviewer)
                                    by {{ \App\Support\InventoryAiSpecPresenter::reviewerDisplayName($aiSpec->reviewer) }}
                                @endif
                            </dd>
                        @endif
                        @if($aiSpec->review_notes)
                            <dt class="col-sm-3">Review Notes</dt>
                            <dd class="col-sm-9">{{ $aiSpec->review_notes }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card card-outline card-secondary">
                <div class="card-header">
                    <h3 class="card-title">Existing Inventory Master Values</h3>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tbody>
                            <tr><th>Height</th><td>{{ $existingSpecs['height'] ?? '—' }}</td></tr>
                            <tr><th>Width</th><td>{{ $existingSpecs['width'] ?? '—' }}</td></tr>
                            <tr><th>Length</th><td>{{ $existingSpecs['length'] ?? '—' }}</td></tr>
                            <tr><th>Weight</th><td>{{ $existingSpecs['weight'] ?? '—' }}</td></tr>
                            <tr><th>Linear Unit</th><td>{{ $existingSpecs['linear_unit'] ?? '—' }}</td></tr>
                            <tr><th>Weight Unit</th><td>{{ $existingSpecs['weight_unit'] ?? '—' }}</td></tr>
                        </tbody>
                    </table>
                    <p class="text-muted small mb-0">
                        Display: {{ $existingSpecs['dimensions_display'] ?? '—' }} / {{ $existingSpecs['weight_display'] ?? '—' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card card-outline card-primary">
                <div class="card-header">
                    <h3 class="card-title">AI Suggested Values</h3>
                </div>
                <div class="card-body">
                    @if($aiSpec->status === \App\Models\InventoryMasterAiSpec::STATUS_PENDING)
                        <form action="{{ route('admin.ai-specifications.update', $aiSpec) }}" method="POST" id="editSuggestedForm">
                            @csrf
                            @method('PUT')
                            @include('admin.inventory-ai-specs.partials.spec-fields', [
                                'prefix' => 'edit',
                                'values' => $suggestedSpecs,
                                'linearUnits' => $linearUnits,
                                'weightUnits' => $weightUnits,
                                'readonly' => false,
                            ])
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i class="fas fa-save"></i> Save Suggested Values
                            </button>
                        </form>
                    @else
                        @include('admin.inventory-ai-specs.partials.spec-fields', [
                            'prefix' => 'view',
                            'values' => $suggestedSpecs,
                            'linearUnits' => $linearUnits,
                            'weightUnits' => $weightUnits,
                            'readonly' => true,
                        ])
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($aiSpec->ai_response)
        <div class="card collapsed-card">
            <div class="card-header">
                <h3 class="card-title">Raw AI Response</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="card-body" style="display:none;">
                <pre class="mb-0" style="white-space: pre-wrap;">{{ json_encode($aiSpec->ai_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </div>
    @endif

    @if($aiSpec->status === \App\Models\InventoryMasterAiSpec::STATUS_PENDING)
        <div class="card card-warning">
            <div class="card-header">
                <h3 class="card-title">Manual Review Actions</h3>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.ai-specifications.approve', $aiSpec) }}" method="POST" id="approveForm">
                    @csrf
                    @include('admin.inventory-ai-specs.partials.spec-fields', [
                        'prefix' => 'approve',
                        'values' => $suggestedSpecs,
                        'linearUnits' => $linearUnits,
                        'weightUnits' => $weightUnits,
                        'readonly' => false,
                    ])
                    <div class="form-group">
                        <label for="approve_review_notes">Review Notes (optional)</label>
                        <textarea name="review_notes" id="approve_review_notes" class="form-control" rows="2">{{ old('review_notes') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-success" onclick="return confirm('Approve and apply these values to inventory master?');">
                        <i class="fas fa-check"></i> Approve
                    </button>
                </form>

                <hr>

                <form action="{{ route('admin.ai-specifications.reject', $aiSpec) }}" method="POST" class="mt-3">
                    @csrf
                    <div class="form-group">
                        <label for="reject_review_notes">Rejection Notes (optional)</label>
                        <textarea name="review_notes" id="reject_review_notes" class="form-control" rows="2">{{ old('review_notes') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this AI suggestion? Inventory master will not be updated.');">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </form>
            </div>
        </div>
    @endif

    <a href="{{ route('admin.ai-specifications.index') }}" class="btn btn-default">
        <i class="fas fa-arrow-left"></i> Back to list
    </a>
@stop
