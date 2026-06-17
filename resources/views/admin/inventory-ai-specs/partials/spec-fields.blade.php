@php
    $fieldNames = ['height', 'width', 'length', 'weight', 'linear_unit_id', 'weight_unit_id'];
@endphp

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="{{ $prefix }}_height">Height</label>
            <input type="number" step="0.01" min="0"
                   name="height" id="{{ $prefix }}_height"
                   class="form-control"
                   value="{{ old('height', $values['height'] ?? '') }}"
                   {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label for="{{ $prefix }}_width">Width</label>
            <input type="number" step="0.01" min="0"
                   name="width" id="{{ $prefix }}_width"
                   class="form-control"
                   value="{{ old('width', $values['width'] ?? '') }}"
                   {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="{{ $prefix }}_length">Length</label>
            <input type="number" step="0.01" min="0"
                   name="length" id="{{ $prefix }}_length"
                   class="form-control"
                   value="{{ old('length', $values['length'] ?? '') }}"
                   {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label for="{{ $prefix }}_weight">Weight</label>
            <input type="number" step="0.01" min="0"
                   name="weight" id="{{ $prefix }}_weight"
                   class="form-control"
                   value="{{ old('weight', $values['weight'] ?? '') }}"
                   {{ $readonly ? 'readonly' : '' }}>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="{{ $prefix }}_linear_unit_id">Linear Unit</label>
            @if($readonly)
                <input type="text" class="form-control" readonly value="{{ $values['linear_unit'] ?? '—' }}">
            @else
                <select name="linear_unit_id" id="{{ $prefix }}_linear_unit_id" class="form-control">
                    <option value="">— Select —</option>
                    @foreach($linearUnits as $unit)
                        <option value="{{ $unit->id }}" {{ (string) old('linear_unit_id', $values['linear_unit_id'] ?? '') === (string) $unit->id ? 'selected' : '' }}>
                            {{ $unit->code }} ({{ $unit->name }})
                        </option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label for="{{ $prefix }}_weight_unit_id">Weight Unit</label>
            @if($readonly)
                <input type="text" class="form-control" readonly value="{{ $values['weight_unit'] ?? '—' }}">
            @else
                <select name="weight_unit_id" id="{{ $prefix }}_weight_unit_id" class="form-control">
                    <option value="">— Select —</option>
                    @foreach($weightUnits as $unit)
                        <option value="{{ $unit->id }}" {{ (string) old('weight_unit_id', $values['weight_unit_id'] ?? '') === (string) $unit->id ? 'selected' : '' }}>
                            {{ $unit->code }} ({{ $unit->name }})
                        </option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>
</div>

@if(!$readonly && $prefix === 'edit')
    <div class="form-group">
        <label for="{{ $prefix }}_source_url">Source URL</label>
        <input type="url" name="source_url" id="{{ $prefix }}_source_url"
               class="form-control"
               value="{{ old('source_url', $values['source_url'] ?? '') }}">
    </div>
@endif
