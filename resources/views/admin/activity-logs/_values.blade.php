@php
    $formatValue = function ($value) use (&$formatValue) {
        if ($value === \App\Services\ActivityLogService::REDACTED) {
            return '<span class="badge badge-secondary">redacted</span>';
        }

        if (is_array($value)) {
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return '<pre class="mb-0 small">'.e($json).'</pre>';
        }

        if ($value === null || $value === '') {
            return '<span class="text-muted">—</span>';
        }

        return e((string) $value);
    };
@endphp

@if($values === null)
    <p class="text-muted mb-0">No values recorded.</p>
@elseif($values === [])
    <p class="text-muted mb-0">No values recorded.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
            <thead>
                <tr>
                    <th style="width: 30%">Field</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($values as $field => $value)
                    <tr>
                        <td><code>{{ $field }}</code></td>
                        <td>{!! $formatValue($value) !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
