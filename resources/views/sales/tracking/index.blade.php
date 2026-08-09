<x-app-layout :pageTitle="'Tracking'">
    <x-slot name="header">
        <x-page-header
            title="Tracking"
            description="Status timeline of orders, deliveries and invoices."
            icon="location"
        />
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('sales.tracking.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Order, delivery or invoice number…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-44">
                <x-select name="type" label="Entity" size="sm">
                    <option value="">All entities</option>
                    <option value="orders" @selected(request('type') === 'orders')>Orders</option>
                    <option value="deliveries" @selected(request('type') === 'deliveries')>Deliveries</option>
                    <option value="invoices" @selected(request('type') === 'invoices')>Invoices</option>
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'type']))
                    <x-button href="{{ route('sales.tracking.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($events->isEmpty())
            <x-empty-state icon="location" title="No tracking events" description="Status changes will appear here as orders, deliveries and invoices move through their lifecycle." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Entity</th>
                            <th>Reference</th>
                            <th>Transition</th>
                            <th>Note</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            @php
                                $trackable = $event->trackable;
                                $label = match ($event->trackable_type) {
                                    \App\Models\SalesOrder::class => 'Order',
                                    \App\Models\SalesDeliveryNote::class => 'Delivery',
                                    \App\Models\SalesInvoice::class => 'Invoice',
                                    default => '—',
                                };
                                $url = $trackable ? match ($event->trackable_type) {
                                    \App\Models\SalesOrder::class => route('sales.orders.edit', $trackable),
                                    \App\Models\SalesDeliveryNote::class => route('sales.delivery_notes.edit', $trackable),
                                    \App\Models\SalesInvoice::class => route('sales.invoices.edit', $trackable),
                                    default => '#',
                                } : null;
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap text-ink-soft">{{ $event->created_at->format('M j, Y H:i') }}</td>
                                <td><x-badge color="neutral">{{ $label }}</x-badge></td>
                                <td>
                                    @if ($url)
                                        <a href="{{ $url }}" class="font-medium text-ink hover:text-primary">{{ $trackable?->number }}</a>
                                    @else
                                        <span class="text-ink-faint">#{{ $event->trackable_id }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($event->from_status)
                                        <span class="text-ink-soft">{{ ucfirst(str_replace('_', ' ', $event->from_status)) }}</span>
                                        <span class="text-ink-faint">→</span>
                                    @endif
                                    <span class="font-medium text-ink">{{ ucfirst(str_replace('_', ' ', $event->to_status)) }}</span>
                                </td>
                                <td class="max-w-xs truncate text-ink-soft">{{ $event->note ?: '—' }}</td>
                                <td class="text-ink-soft">{{ $event->user?->name ?: 'System' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($events->hasPages())
            <div class="px-5 py-4">
                {{ $events->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
