@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'disabled' => false,
])

@php
    $classes = match ($variant) {
        'primary' => 'btn-primary',
        'secondary' => 'btn-secondary',
        'ghost' => 'btn-ghost',
        'danger' => 'btn-danger',
        'danger-secondary' => 'btn-danger-secondary',
        'success' => 'btn-success',
        default => 'btn-primary',
    };

    $sizeClasses = match ($size) {
        'sm' => 'btn-sm',
        'lg' => 'btn-lg',
        default => 'btn-md',
    };

    $merged = $attributes->merge([
        'class' => trim("{$classes} {$sizeClasses}"),
    ]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $merged }}>
        @if ($icon)
            <x-icon :name="$icon" class="size-4 shrink-0" />
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $disabled ? 'disabled' : '' }} {{ $merged }}>
        @if ($icon)
            <x-icon :name="$icon" class="size-4 shrink-0" />
        @endif
        {{ $slot }}
    </button>
@endif
