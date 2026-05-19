@php
    $selectedSection = old('section', $selectedSection ?? config('cms.default_section', 'general'));
    $sortOrder = old('sort_order', $sortOrder ?? 0);
@endphp
<div class="form-group">
    <label for="section">Menu section</label>
    <select class="form-control @error('section') is-invalid @enderror" id="section" name="section" required>
        @foreach($sections as $value => $label)
            <option value="{{ $value }}" @selected($selectedSection === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @error('section')<span class="invalid-feedback">{{ $message }}</span>@enderror
    <small class="form-text text-muted">Choose <strong>About Us menu</strong> to list this page under the frontend About Us dropdown.</small>
</{{ 'div' }}>
<div class="form-group">
    <label for="sort_order">Sort order</label>
    <input type="number" class="form-control @error('sort_order') is-invalid @enderror" id="sort_order" name="sort_order" value="{{ $sortOrder }}" min="0" max="9999" step="1">
    @error('sort_order')<span class="invalid-feedback">{{ $message }}</span>@enderror
    <small class="form-text text-muted">Lower numbers appear first in the menu (0 = default).</small>
</div>
