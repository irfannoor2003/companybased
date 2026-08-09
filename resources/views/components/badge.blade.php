@props([
    'color' => 'neutral',
    'dot' => false,
])

@php
    $classes = match ($color) {
        'primary' => 'bg-primary/10 text-primary',
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        'danger' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
        'info' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400',
        'neutral' => 'bg-surface-muted text-ink-soft',
        'outline' => 'border border-line text-ink-soft',
        default => 'bg-surface-muted text-ink-soft',
    };

    $dotClasses = match ($color) {
        'success' => 'bg-emerald-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-rose-500',
        'info' => 'bg-sky-500',
        'primary' => 'bg-primary',
        default => 'bg-ink-faint',
    };
@endphp

<span {{ $attributes->merge(['class' => "badge {$classes}"]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $dotClasses }}"></span>
    @endif
    {{ $slot }}
</span>
