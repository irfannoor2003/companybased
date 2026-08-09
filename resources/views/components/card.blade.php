@props([
    'title' => null,
    'description' => null,
    'padding' => true,
    'hover' => false,
    'body' => null,
])

<div {{ $attributes->merge(['class' => $hover ? 'surface-card' : 'surface']) }}>
    @if ($title || isset($header) || isset($actions))
        <div class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <div class="min-w-0">
                @if ($title)
                    <h3 class="text-sm font-semibold text-ink">{{ $title }}</h3>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-xs text-ink-faint">{{ $description }}</p>
                @endif
                @isset($header)
                    <div class="mt-1">{{ $header }}</div>
                @endisset
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    @if ($padding)
        <div class="px-5 py-5 {{ ($title || isset($header) || isset($actions)) ? '' : '' }}">
            {{ $slot }}
        </div>
    @else
        {{ $slot }}
    @endif

    @isset($footer)
        <div class="border-t border-line px-5 py-3">
            {{ $footer }}
        </div>
    @endisset
</div>
