@extends('adminlte::page')

@section('title', 'System Configurations')

@section('content_header')
    <h1>System Configurations</h1>
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

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <h5><i class="icon fas fa-ban"></i> Validation Error!</h5>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.system-configurations.update') }}" method="POST">
        @csrf
        @method('PUT')

        @foreach($configurations as $category => $configs)
            <div class="card card-{{ $category === 'regional' ? 'info' : 'success' }}">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-{{ $category === 'regional' ? 'globe' : 'dollar-sign' }}"></i>
                        {{ ucfirst($category) }} Settings
                    </h3>
                </div>
                <div class="card-body">
                    @foreach($configs as $config)
                        <div class="form-group">
                            <label for="config_{{ $config->key }}">
                                {{ $config->label }}
                                @if($config->description)
                                    <small class="text-muted">({{ $config->description }})</small>
                                @endif
                            </label>
                            
                            @if($config->type === 'select' && $config->options)
                                @php
                                    $options = is_array($config->options) ? $config->options : json_decode($config->options, true);
                                @endphp
                                <select 
                                    class="form-control @error('configurations.' . $config->key) is-invalid @enderror"
                                    id="config_{{ $config->key }}"
                                    name="configurations[{{ $config->key }}]"
                                    required>
                                    @foreach($options as $optionValue => $optionLabel)
                                        <option value="{{ $optionValue }}" 
                                            {{ old('configurations.' . $config->key, $config->value) == $optionValue ? 'selected' : '' }}>
                                            {{ $optionLabel }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input 
                                    type="text"
                                    class="form-control @error('configurations.' . $config->key) is-invalid @enderror"
                                    id="config_{{ $config->key }}"
                                    name="configurations[{{ $config->key }}]"
                                    value="{{ old('configurations.' . $config->key, $config->value) }}"
                                    @if($config->description)
                                        placeholder="{{ $config->description }}"
                                    @endif
                                    required>
                            @endif
                            
                            @error('configurations.' . $config->key)
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            
                            @if($config->description)
                                <small class="form-text text-muted">{{ $config->description }}</small>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="card">
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a href="{{ route('dashboard') }}" class="btn btn-default">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </div>
    </form>
@stop

@section('js')
<script>
    $(document).ready(function() {
        // Add any client-side validation or enhancements if needed
    });
</script>
@stop




