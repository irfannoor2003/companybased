@php
    $tabs = [
        ['label' => 'Overview', 'route' => 'cash_flow.overview'],
        ['label' => 'Inflows', 'route' => 'cash_flow.inflows'],
        ['label' => 'Outflows', 'route' => 'cash_flow.outflows'],
        ['label' => 'Forecast', 'route' => 'cash_flow.forecast'],
        ['label' => 'Reports', 'route' => 'cash_flow.reports'],
    ];
@endphp

<div class="mt-6 border-b border-line">
    <nav class="flex flex-wrap gap-1">
        @foreach ($tabs as $tab)
            @php
                $active = request()->routeIs($tab['route']);
            @endphp
            <a href="{{ route($tab['route']) }}"
                class="rounded-t-lg px-4 py-2.5 text-sm font-medium transition {{ $active ? 'border-b-2 border-primary bg-primary/5 text-primary' : 'text-ink-soft hover:bg-surface-muted/60 hover:text-ink' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
