@php
    $colors = [
        'created' => 'success',
        'updated' => 'info',
        'deleted' => 'danger',
        'restored' => 'warning',
    ];
    $color = $colors[$action] ?? 'secondary';
@endphp
<span class="badge badge-{{ $color }}">{{ ucfirst($action) }}</span>
