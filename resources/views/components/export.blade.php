@props([
    'route' => null,
    'params' => [],
    'size' => 'md',
    'include' => ['csv', 'pdf', 'json'],
])

@php
    $formats = [
        'csv' => ['label' => 'CSV', 'icon' => 'export'],
        'pdf' => ['label' => 'PDF', 'icon' => 'document'],
        'json' => ['label' => 'JSON', 'icon' => 'download'],
    ];
    $available = array_values(array_filter($formats, fn ($f, $k) => in_array($k, $include, true), ARRAY_FILTER_USE_BOTH));
@endphp

<div x-data="{ open: false }" @click.outside="open = false" class="relative inline-block" {{ $attributes->only('class') }}>
    <div @click="open = !open">
        <x-button variant="secondary" type="button" size="{{ $size }}" icon="export">Export</x-button>
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
        class="absolute right-0 z-30 mt-2 w-44 origin-top-right rounded-xl border border-line bg-surface p-1.5 shadow-lift animate-pop-in"
    >
        @foreach ($available as $key => $def)
            @php
                $query = array_merge(request()->query(), $params, ['format' => $key]);
                $url = route($route, $params).(count($query) ? '?'.http_build_query($query) : '');
            @endphp
            <a href="{{ $url }}"
                class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-soft transition-colors hover:bg-surface-muted/60 hover:text-ink">
                <x-icon :name="$def['icon']" class="size-4 text-ink-faint" />
                Export as {{ $def['label'] }}
            </a>
        @endforeach
    </div>
</div>