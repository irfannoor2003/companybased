@props([
    'label' => null,
    'value' => null,
    'icon' => null,
    'hint' => null,
    'tone' => 'primary',
    'href' => null,
])

@php
    $tones = [
        'primary' => 'bg-primary/10 text-primary',
        'success' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
        'warning' => 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        'danger' => 'bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-400',
        'info' => 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
        'neutral' => 'bg-surface-muted text-ink-soft',
    ];
    $toneClass = $tones[$tone] ?? $tones['primary'];
@endphp

<div class="surface-card p-5">
    <div class="flex items-start justify-between">
        <div class="flex size-10 items-center justify-center rounded-lg {{ $toneClass }}">
            <x-icon :name="$icon" class="size-5" />
        </div>
        @isset($extra)
            <div>{{ $extra }}</div>
        @endisset
    </div>

    <p class="mt-4 text-2xl font-bold tracking-tight text-ink">{{ $value }}</p>
    <p class="mt-0.5 text-sm text-ink-soft">{{ $label }}</p>

    @if ($hint)
        <p class="mt-2 text-xs text-ink-faint">{{ $hint }}</p>
    @endif
</div>
