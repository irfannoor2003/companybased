@props(['status' => ''])

@php
    $colors = [
        'active' => 'success',
        'inactive' => 'neutral',
        'draft' => 'neutral',
        'pending' => 'warning',
        'in_progress' => 'warning',
        'in_transit' => 'info',
        'arrived' => 'success',
        'completed' => 'success',
        'approved' => 'success',
        'cancelled' => 'danger',
        'low' => 'danger',
        'out' => 'danger',
        'ok' => 'success',
    ];
@endphp

<x-badge :color="$colors[$status] ?? 'neutral'" dot>{{ \Illuminate\Support\Str::of($status)->replace('_', ' ')->headline() }}</x-badge>
