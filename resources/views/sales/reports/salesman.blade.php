<x-app-layout :pageTitle="'Sales by Salesman'">
    <x-slot name="header">
        <x-page-header title="Sales by Salesman" description="Orders attributed to each salesman" icon="sales">
            <x-slot name="actions">
                <x-button href="{{ route('sales.reports.salesman.export', request()->query()) }}" variant="secondary" icon="download">Export CSV</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card class="mt-6">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            <div class="w-48">
                <x-select name="salesman_id" label="Salesman">
                    <option value="">All Salesmen</option>
                    @foreach ($salesmen as $s)
                        <option value="{{ $s->id }}" @selected(request('salesman_id') == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-40">
                <x-input name="from" type="date" label="From" value="{{ request('from') }}" />
            </div>
            <div class="w-40">
                <x-input name="to" type="date" label="To" value="{{ request('to') }}" />
            </div>
            <x-button type="submit" icon="search">Filter</x-button>
            @if (request()->hasAny(['salesman_id', 'from', 'to']))
                <x-button href="{{ route('sales.reports.salesman') }}" variant="ghost">Clear</x-button>
            @endif
        </form>
    </x-card>

    @if ($summary->count())
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($summary as $row)
                <x-card>
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <x-icon name="users" class="size-5" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-ink">{{ $row->salesman?->name ?? 'Unassigned' }}</p>
                            <p class="text-xs text-ink-soft">{{ $row->order_count }} orders · {{ currency_format($row->total_value) }}</p>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif

    <x-card title="Orders" class="mt-6" :padding="false">
        @if ($orders->count())
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-surface-muted text-left text-xs font-medium uppercase tracking-wider text-ink-soft">
                        <tr>
                            <th class="px-4 py-3">Order #</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Salesman</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($orders as $order)
                            <tr class="hover:bg-surface-muted/50">
                                <td class="whitespace-nowrap px-4 py-3 font-medium">
                                    <a href="{{ route('sales.orders.edit', $order) }}" class="text-primary hover:underline">{{ $order->number }}</a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $order->issue_date }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $order->salesman?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $order->customer?->company_name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <x-badge :value="$order->status" :color="match($order->status) { 'confirmed' => 'success', 'packed' => 'info', 'shipped' => 'warning', 'delivered' => 'success', default => 'neutral' }" />
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ currency_format($order->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line px-4 py-3">
                {{ $orders->links() }}
            </div>
        @else
            <x-empty-state icon="orders" title="No orders found" description="No confirmed orders match your filters." />
        @endif
    </x-card>
</x-app-layout>
