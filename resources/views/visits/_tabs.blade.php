@php
    $tabs = [
        ['label' => 'Visits', 'route' => 'visits.index', 'icon' => 'visits', 'permission' => 'visits.visits.view'],
        ['label' => 'Map View', 'route' => 'visits.map', 'icon' => 'map', 'permission' => 'visits.map_view.view'],
    ];
    $visibleTabs = array_values(array_filter($tabs, fn ($t) => auth()->user()->can($t['permission'])));
@endphp

@if (count($visibleTabs) > 1)
    <div class="mt-6 border-b border-line">
        <nav class="flex flex-wrap gap-1">
            @foreach ($visibleTabs as $tab)
                @php
                    $active = request()->routeIs($tab['route']) || request()->routeIs(str_replace('.index', '.', $tab['route']).'*');
                @endphp
                <a href="{{ route($tab['route']) }}"
                    class="inline-flex items-center gap-2 rounded-t-lg px-4 py-2.5 text-sm font-medium transition {{ $active ? 'border-b-2 border-primary bg-primary/5 text-primary' : 'text-ink-soft hover:bg-surface-muted/60 hover:text-ink' }}">
                    <x-icon :name="$tab['icon']" class="size-4" />
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
    </div>
@endif