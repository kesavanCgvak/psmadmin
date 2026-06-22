@php
    /** @var \App\Models\Product|null $selectedProduct */
    $selectedProduct = $selectedProduct ?? null;
    $productSelectId = $productSelectId ?? 'product_id';
@endphp

<div class="form-group">
    <label for="{{ $productSelectId }}">Product <span class="text-danger">*</span></label>
    <select class="form-control product-search-select @error('product_id') is-invalid @enderror"
            id="{{ $productSelectId }}"
            name="product_id"
            required
            data-search-url="{{ route('admin.products.search') }}">
        <option value=""></option>
        @if($selectedProduct)
            <option value="{{ $selectedProduct->id }}" selected>
                @php
                    $label = trim(($selectedProduct->brand->name ?? '') . ' ' . ($selectedProduct->model ?? ''));
                    if ($selectedProduct->psm_code) {
                        $label .= ' (' . $selectedProduct->psm_code . ')';
                    }
                @endphp
                {{ $label }}
            </option>
        @endif
    </select>
    @error('product_id')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
    <small class="form-text text-muted">Type at least 2 characters to search by model, PSM code, or brand.</small>
</div>


