@props(['status' => '', 'module' => 'sales'])

@php
    // Single source of truth for status presentation, in config/statuses.php.
    // The per-module wrappers (x-sales.status-badge and friends) pass their own
    // module key so the existing, deliberately per-module colours are preserved.
    $map = config("statuses.{$module}", []);
    [$color, $label] = $map[$status] ?? ['neutral', ucfirst(str_replace('_', ' ', (string) $status))];
@endphp

<x-badge :color="$color" dot>{{ $label }}</x-badge>
