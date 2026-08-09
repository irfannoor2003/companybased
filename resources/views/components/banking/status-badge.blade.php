@props(['status' => 'draft'])

@php
    $map = [
        'draft' => ['neutral', 'Draft'],
        'completed' => ['success', 'Completed'],
        'cancelled' => ['danger', 'Cancelled'],
        'active' => ['success', 'Active'],
        'inactive' => ['neutral', 'Inactive'],
    ];
    [$color, $label] = $map[$status] ?? ['neutral', ucfirst(str_replace('_', ' ', $status))];
@endphp

<x-badge :color="$color" dot>{{ $label }}</x-badge>
