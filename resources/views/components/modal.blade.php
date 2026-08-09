@props([
    'name' => null,
    'title' => null,
    'description' => null,
    'maxWidth' => 'lg',
    'footer' => null,
])

@php
    $widths = [
        'sm' => 'max-w-md',
        'md' => 'max-w-lg',
        'lg' => 'max-w-2xl',
        'xl' => 'max-w-4xl',
        '2xl' => 'max-w-6xl',
    ];
    $max = $widths[$maxWidth] ?? $widths['lg'];
@endphp

<div
    x-data="{ show: false }"
    x-init="show = true"
    x-show="show"
    x-cloak
    @keydown.escape.window="show = false"
    @if ($name)
        @click.outside="show = false"
    @endif
    role="dialog"
    aria-modal="true"
    class="fixed inset-0 z-50 overflow-y-auto"
>
    <div class="fixed inset-0 bg-slate-950/50 backdrop-blur-sm animate-fade-in" @click="show = false"></div>

    <div class="flex min-h-full items-end justify-center p-4 sm:items-center">
        <div
            class="relative w-full {{ $max }} rounded-2xl bg-surface shadow-modal animate-pop-in"
            {{ $attributes }}
        >
            <div class="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
                <div class="min-w-0">
                    @if ($title)
                        <h3 class="text-base font-semibold text-ink">{{ $title }}</h3>
                    @endif
                    @if ($description)
                        <p class="mt-0.5 text-xs text-ink-faint">{{ $description }}</p>
                    @endif
                </div>
                <button type="button" class="btn-ghost btn-icon" @click="show = false" aria-label="Close">
                    <x-icon name="x" class="size-5" />
                </button>
            </div>

            <div class="px-6 py-5">
                {{ $slot }}
            </div>

            @if ($footer)
                <div class="flex items-center justify-end gap-3 border-t border-line bg-surface-muted/50 px-6 py-4 rounded-b-2xl">
                    {{ $footer }}
                </div>
            @endif
        </div>
    </div>
</div>
