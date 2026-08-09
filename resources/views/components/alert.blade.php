@props([
    'type' => 'info',
    'dismissible' => false,
    'title' => null,
])

@php
    $styles = [
        'info' => [
            'wrap' => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200',
            'icon' => 'text-sky-500',
            'name' => 'info',
        ],
        'success' => [
            'wrap' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
            'icon' => 'text-emerald-500',
            'name' => 'check-circle',
        ],
        'warning' => [
            'wrap' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
            'icon' => 'text-amber-500',
            'name' => 'warning',
        ],
        'danger' => [
            'wrap' => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200',
            'icon' => 'text-rose-500',
            'name' => 'warning',
        ],
    ];
    $style = $styles[$type] ?? $styles['info'];
@endphp

<div
    {{ $attributes->merge(['class' => "flex items-start gap-3 rounded-xl border p-4 text-sm {$style['wrap']}"]) }}
    x-data="{ visible: true }"
    x-show="visible"
    x-transition
>
    <x-icon :name="$style['name']" :class="'mt-0.5 size-5 shrink-0 ' . $style['icon']" />
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div class="{{ $title ? 'mt-0.5' : '' }}">{{ $slot }}</div>
    </div>
    @if ($dismissible)
        <button type="button" class="shrink-0 opacity-60 hover:opacity-100" @click="visible = false" aria-label="Dismiss">
            <x-icon name="x" class="size-4" />
        </button>
    @endif
</div>
