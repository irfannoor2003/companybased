<x-app-layout :pageTitle="'Delivery Challans Report'">
    <x-slot name="header">
        <x-page-header title="Delivery Challans Report" description="Goods issued — challans, quantities and delivery status" icon="truck">
            <x-slot name="actions">
                <x-button href="{{ route('reports.registers.delivery_challans.export', request()->query()) }}" variant="secondary" icon="download">Export CSV</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card class="mt-6">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            <div class="w-40">
                <x-input name="from" type="date" label="From" value="{{ request('from') }}" />
            </div>
            <div class="w-40">
                <x-input name="to" type="date" label="To" value="{{ request('to') }}" />
            </div>
            <div class="w-44">
                <x-select name="status" label="Status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-56">
                <x-select name="customer_id" label="Customer">
                    <option value="">All customers</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected(request('customer_id') == $c->id)>{{ $c->company_name }}</option>
                    @endforeach
                </x-select>
            </div>
            <x-button type="submit" icon="search">Filter</x-button>
            @if (request()->hasAny(['from', 'to', 'status', 'customer_id']))
                <x-button href="{{ route('reports.registers.delivery_challans') }}" variant="ghost">Clear</x-button>
            @endif
        </form>
    </x-card>

    <x-card title="Challans" class="mt-6" :padding="false">
        @if ($rows->count())
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-surface-muted text-left text-xs font-medium uppercase tracking-wider text-ink-soft">
                        <tr>
                            <th class="px-4 py-3">Number</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Order</th>
                            <th class="px-4 py-3">Carrier</th>
                            <th class="px-4 py-3">Tracking #</th>
                            <th class="px-4 py-3 text-right">Qty shipped</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($rows as $note)
                            <tr class="hover:bg-surface-muted/50">
                                <td class="whitespace-nowrap px-4 py-3 font-medium">
                                    @if (auth()->user()->can('sales.delivery_notes.view'))
                                        <a href="{{ route('sales.delivery_notes.show', $note) }}" class="text-primary-ink hover:underline">{{ $note->number }}</a>
                                    @else
                                        {{ $note->number }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $note->issue_date }}</td>
                                <td class="px-4 py-3">{{ $note->customer?->company_name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $note->order_id > 0 ? '#'.$note->order_id : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $note->carrier ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $note->tracking_number ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ $quantities[$note->id] ?? 0 }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <x-badge :value="$note->status" :color="match($note->status) {
                                        'delivered' => 'success',
                                        'shipped' => 'warning',
                                        'pending' => 'info',
                                        'cancelled' => 'danger',
                                        default => 'neutral',
                                    }" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-line bg-surface-muted font-medium">
                        <tr>
                            <td class="px-4 py-3" colspan="6">Total ({{ $rows->count() }} challans)</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ $quantities->sum() }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <x-empty-state icon="truck" title="No challans found" description="No delivery challans match your filters." />
        @endif
    </x-card>

    <p class="mt-3 text-xs text-ink-faint">
        Delivery notes carry no money on purpose — they move goods, not payments. "Qty shipped" is the quantity across every
        line of the challan; "pending" challans have been written but not yet marked shipped.
    </p>
</x-app-layout>
