@props([
    'align' => 'right',
])

@php
    $alignClasses = [
        'right' => 'right-0 origin-top-right',
        'left' => 'left-0 origin-top-left',
    ];
    $alignClass = $alignClasses[$align] ?? $alignClasses['right'];
@endphp

<div x-data="{ open: false }" @click.outside="open = false" class="relative" {{ $attributes }}>
    <div @click="open = !open">
        {{ $trigger }}
    </div>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        @keydown.escape.window="open = false"
        x-cloak
        class="absolute {{ $alignClass }} z-30 mt-2 w-48 rounded-xl border border-line bg-surface p-1.5 shadow-lift animate-pop-in"
    >
        {{ $slot }}
    </div>
</div>
