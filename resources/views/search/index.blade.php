<x-app-layout :pageTitle="'Search'">
    <x-slot name="header">
        <x-page-header
            title="Search"
            :description="$term ? 'Results for “' . $term . '”' : 'Type something to search across the system.'"
            icon="search"
        >
            <x-slot name="actions">
                <x-button href="{{ route('dashboard') }}" variant="secondary" icon="arrow-left">Back to dashboard</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- Repeatable search box; submits to the same route --}}
    <x-card class="mb-6">
        <form method="GET" action="{{ route('search') }}" class="flex items-end gap-3">
            <div class="flex-1">
                <x-input
                    name="q"
                    label="Search"
                    :value="$term"
                    placeholder="Customer name, order number, SKU, invoice number, employee…"
                    autofocus
                />
            </div>
            <x-button type="submit" icon="search">Search</x-button>
        </form>
    </x-card>

    @if (mb_strlen($term) < 2)
        <x-card>
            <div class="py-8 text-center">
                <p class="text-sm text-ink-soft">Enter at least two characters to search.</p>
                @if ($entities !== [])
                    <p class="mt-4 text-xs text-ink-faint">You can search: {{ collect($entities)->pluck('label')->join(', ') }}</p>
                @endif
            </div>
        </x-card>
    @elseif ($grouped->isEmpty())
        <x-card>
            <div class="py-8 text-center">
                <p class="text-sm font-semibold text-ink">No matches for “{{ $term }}”</p>
                <p class="mt-1 text-sm text-ink-soft">Try a different spelling, or a shorter search term.</p>
            </div>
        </x-card>
    @else
        <div class="space-y-6">
            @foreach ($grouped as $label => $items)
                <x-card :title="$label" :description="$items->count().' match'.($items->count() === 1 ? '' : 'es')">
                    <ul class="divide-y divide-line">
                        @foreach ($items as $item)
                            <li>
                                <a
                                    href="{{ $item['url'] ?? '#' }}"
                                    @class([
                                        'flex items-center gap-4 px-1 py-3 transition hover:bg-surface-muted',
                                        'pointer-events-none opacity-60' => ! $item['url'],
                                    ])
                                >
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <x-icon :name="$item['icon']" class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $item['title'] }}</p>
                                        @if ($item['subtitle'])
                                            <p class="truncate text-xs text-ink-soft">{{ $item['subtitle'] }}</p>
                                        @endif
                                    </div>
                                    @if ($item['meta'])
                                        <span class="shrink-0 rounded-md bg-surface-muted px-2 py-1 text-xs font-medium text-ink-soft">{{ $item['meta'] }}</span>
                                    @endif
                                    @if ($item['url'])
                                        <x-icon name="chevron-right" class="size-4 shrink-0 text-ink-faint" />
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endforeach
        </div>
    @endif
</x-app-layout>
