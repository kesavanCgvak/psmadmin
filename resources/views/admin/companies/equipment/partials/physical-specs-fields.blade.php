@php
    /** @var \App\Models\Equipment|null $equipment */
    $equipment = $equipment ?? null;
@endphp

<hr>
<h5 class="mb-3">Physical specifications</h5>
<p class="text-muted small">Select a product to auto-fill from the catalog. You can adjust values before saving.</p>

<div class="row">
    <div class="col-md-3">
        <div class="form-group">
            <label for="height">Height</label>
            <input type="number"
                   class="form-control @error('height') is-invalid @enderror"
                   id="height"
                   name="height"
                   value="{{ old('height', $equipment->height ?? '') }}"
                   placeholder="0.00"
                   step="0.01"
                   min="0">
            @error('height')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label for="width">Width</label>
            <input type="number"
                   class="form-control @error('width') is-invalid @enderror"
                   id="width"
                   name="width"
                   value="{{ old('width', $equipment->width ?? '') }}"
                   placeholder="0.00"
                   step="0.01"
                   min="0">
            @error('width')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label for="length">Length</label>
            <input type="number"
                   class="form-control @error('length') is-invalid @enderror"
                   id="length"
                   name="length"
                   value="{{ old('length', $equipment->length ?? '') }}"
                   placeholder="0.00"
                   step="0.01"
                   min="0">
            @error('length')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label for="linear_unit_id">Linear unit</label>
            <select class="form-control @error('linear_unit_id') is-invalid @enderror"
                    id="linear_unit_id"
                    name="linear_unit_id">
                <option value="">-- Select unit --</option>
                @foreach($linearUnits as $unit)
                    <option value="{{ $unit->id }}" {{ (string) old('linear_unit_id', $equipment->linear_unit_id ?? '') === (string) $unit->id ? 'selected' : '' }}>
                        {{ $unit->name }} ({{ $unit->code }})
                    </option>
                @endforeach
            </select>
            @error('linear_unit_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="form-group">
            <label for="weight">Weight</label>
            <input type="number"
                   class="form-control @error('weight') is-invalid @enderror"
                   id="weight"
                   name="weight"
                   value="{{ old('weight', $equipment->weight ?? '') }}"
                   placeholder="0.00"
                   step="0.01"
                   min="0">
            @error('weight')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label for="weight_unit_id">Weight unit</label>
            <select class="form-control @error('weight_unit_id') is-invalid @enderror"
                    id="weight_unit_id"
                    name="weight_unit_id">
                <option value="">-- Select unit --</option>
                @foreach($weightUnits as $unit)
                    <option value="{{ $unit->id }}" {{ (string) old('weight_unit_id', $equipment->weight_unit_id ?? '') === (string) $unit->id ? 'selected' : '' }}>
                        {{ $unit->name }} ({{ $unit->code }})
                    </option>
                @endforeach
            </select>
            @error('weight_unit_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label for="country_of_origin">Country of origin</label>
            <input type="text"
                   class="form-control @error('country_of_origin') is-invalid @enderror"
                   id="country_of_origin"
                   name="country_of_origin"
                   value="{{ old('country_of_origin', $equipment->country_of_origin ?? '') }}"
                   maxlength="100"
                   placeholder="e.g., United States">
            @error('country_of_origin')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="form-group mb-0">
            <label for="hsn_code">HSN code</label>
            <input type="text"
                   class="form-control @error('hsn_code') is-invalid @enderror"
                   id="hsn_code"
                   name="hsn_code"
                   value="{{ old('hsn_code', $equipment->hsn_code ?? '') }}"
                   maxlength="20"
                   placeholder="e.g., 8518">
            @error('hsn_code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
</div>

