@extends('adminlte::page')

@section('title', 'Sub-Category Details')

@section('content_header')
    <h1>Sub-Category Details</h1>
@stop

@section('css')
    @include('partials.responsive-css')
@stop

@section('content')
    <div class="row">
        <div class="col-md-6">
            <div class="card card-info">
                <div class="card-header">
                    <h3 class="card-title">{{ $subcategory->name }}</h3>
                </div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">ID</dt>
                        <dd class="col-sm-8">{{ $subcategory->id }}</dd>

                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $subcategory->name }}</dd>

                        <dt class="col-sm-4">Category</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-primary">{{ $subcategory->category?->name ?? 'N/A' }}</span>
                        </dd>

                        <dt class="col-sm-4">Products</dt>
                        <dd class="col-sm-8">
                            <span class="badge badge-success">{{ $subcategory->products->count() }}</span>
                        </dd>

                        <dt class="col-sm-4">Created At</dt>
                        <dd class="col-sm-8">{{ $subcategory->created_at?->format('M d, Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Updated At</dt>
                        <dd class="col-sm-8">{{ $subcategory->updated_at?->format('M d, Y H:i:s') }}</dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <a href="{{ route('admin.subcategories.edit', $subcategory) }}" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="{{ route('admin.subcategories.index') }}" class="btn btn-default">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Products in this Sub-Category</h3>
                    @if($subcategory->products->count() > 0)
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-primary" id="selectAllBtn">
                                <i class="fas fa-check-square"></i> Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" id="deselectAllBtn">
                                <i class="fas fa-square"></i> Deselect All
                            </button>
                        </div>
                    @endif
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    @if($subcategory->products->count() > 0)
                        <form id="moveProductsForm">
                            @csrf
                            <div class="mb-3">
                                <label for="targetSubcategory" class="form-label">Move selected products to:</label>
                                <select class="form-control" id="targetSubcategory" name="target_subcategory_id" required>
                                    <option value="">-- Select Sub-Category --</option>
                                    @foreach($allSubCategories as $categoryName => $subCategories)
                                        <optgroup label="{{ $categoryName }}">
                                            @foreach($subCategories as $subcat)
                                                <option value="{{ $subcat->id }}">{{ $subcat->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <button type="button" class="btn btn-success btn-sm mb-3" id="moveProductsBtn" disabled>
                                <i class="fas fa-arrow-right"></i> Move Selected Products
                            </button>
                            <div class="mb-2">
                                <small class="text-muted">
                                    <span id="selectedCount">0</span> product(s) selected
                                </small>
                            </div>
                            <ul class="list-group">
                                @foreach($subcategory->products as $product)
                                    <li class="list-group-item">
                                        <div class="form-check">
                                            <input class="form-check-input product-checkbox" 
                                                   type="checkbox" 
                                                   value="{{ $product->id }}" 
                                                   id="product_{{ $product->id }}">
                                            <label class="form-check-label" for="product_{{ $product->id }}" style="width: 100%;">
                                                <strong>{{ $product->brand?->name }}</strong> - {{ $product->model }}
                                                @if($product->psm_code)
                                                    <br><small class="text-muted">PSM Code: {{ $product->psm_code }}</small>
                                                @endif
                                            </label>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </form>
                    @else
                        <p class="text-muted">No products in this sub-category yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Move Products Modal -->
    <div class="modal fade" id="moveProductsModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Move Products</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to move <span id="moveCount">0</span> product(s) to the selected sub-category?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmMoveBtn">
                        <i class="fas fa-arrow-right"></i> Confirm Move
                    </button>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
<script>
$(document).ready(function() {
    const checkboxes = $('.product-checkbox');
    const selectAllBtn = $('#selectAllBtn');
    const deselectAllBtn = $('#deselectAllBtn');
    const moveProductsBtn = $('#moveProductsBtn');
    const targetSubcategory = $('#targetSubcategory');
    const selectedCountSpan = $('#selectedCount');
    const moveProductsForm = $('#moveProductsForm');
    const moveProductsModal = $('#moveProductsModal');
    const confirmMoveBtn = $('#confirmMoveBtn');

    // Update selected count and enable/disable move button
    function updateSelection() {
        const selected = checkboxes.filter(':checked').length;
        selectedCountSpan.text(selected);
        moveProductsBtn.prop('disabled', selected === 0 || !targetSubcategory.val());
    }

    // Checkbox change handler
    checkboxes.on('change', updateSelection);
    targetSubcategory.on('change', updateSelection);

    // Select all
    selectAllBtn.on('click', function() {
        checkboxes.prop('checked', true);
        updateSelection();
    });

    // Deselect all
    deselectAllBtn.on('click', function() {
        checkboxes.prop('checked', false);
        updateSelection();
    });

    // Move products button click
    moveProductsBtn.on('click', function() {
        const selected = checkboxes.filter(':checked');
        if (selected.length === 0) {
            alert('Please select at least one product to move.');
            return;
        }
        if (!targetSubcategory.val()) {
            alert('Please select a target sub-category.');
            return;
        }
        $('#moveCount').text(selected.length);
        moveProductsModal.modal('show');
    });

    // Confirm move
    confirmMoveBtn.on('click', function() {
        const selectedIds = checkboxes.filter(':checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedIds.length === 0) {
            alert('No products selected.');
            return;
        }

        // Disable button during request
        confirmMoveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Moving...');

        $.ajax({
            url: '{{ route("admin.subcategories.moveProducts", $subcategory) }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                product_ids: selectedIds,
                target_subcategory_id: targetSubcategory.val()
            },
            success: function(response) {
                moveProductsModal.modal('hide');
                if (response.success) {
                    // Show success message
                    alert(response.message);
                    // Reload page to show updated product list
                    location.reload();
                } else {
                    alert('Error: ' + (response.message || 'Failed to move products'));
                }
            },
            error: function(xhr) {
                moveProductsModal.modal('hide');
                let errorMsg = 'Failed to move products.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                alert(errorMsg);
            },
            complete: function() {
                confirmMoveBtn.prop('disabled', false).html('<i class="fas fa-arrow-right"></i> Confirm Move');
            }
        });
    });
});
</script>
@stop

