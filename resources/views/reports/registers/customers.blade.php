<x-app-layout :pageTitle="'Customers Report'">
    <x-slot name="header">
        <x-page-header title="Customers Report" description="Balances owed by each customer" icon="users">
            <x-slot name="actions">
                <x-button href="{{ route('reports.registers.customers.export', request()->query()) }}" variant="secondary" icon="download">Export CSV</x-button>
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
            <x-button type="submit" icon="search">Filter</x-button>
            @if (request()->hasAny(['from', 'to']))
                <x-button href="{{ route('reports.registers.customers') }}" variant="ghost">Clear</x-button>
            @endif
        </form>
    </x-card>

    <x-card title="Balances" class="mt-6" :padding="false">
        @if (count($rows))
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-surface-muted text-left text-xs font-medium uppercase tracking-wider text-ink-soft">
                        <tr>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3 text-right">Invoiced</th>
                            <th class="px-4 py-3 text-right">Payments received</th>
                            <th class="px-4 py-3 text-right">Credit notes applied</th>
                            <th class="px-4 py-3 text-right">Balance due</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-surface-muted/50">
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $row['code'] ?: '—' }}</td>
                                <td class="px-4 py-3 font-medium">
                                    @if (auth()->user()->can('sales.customers.view'))
                                        <a href="{{ route('sales.customers.statement', $row['id']) }}" class="text-primary-ink hover:underline">{{ $row['name'] }}</a>
                                    @else
                                        {{ $row['name'] }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($row['invoiced']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right {{ $row['received'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : '' }}">{{ money($row['received']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right {{ $row['credited'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : '' }}">{{ money($row['credited']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium {{ $row['balance'] < 0 ? 'text-sky-600' : '' }}">{{ money($row['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-line bg-surface-muted font-medium">
                        <tr>
                            <td class="px-4 py-3" colspan="2">Total</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($totals['invoiced']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($totals['received']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($totals['credited']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($totals['balance']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <x-empty-state icon="users" title="No customers yet" description="Add customers to see their balances here." />
        @endif
    </x-card>

    <p class="mt-3 text-xs text-ink-faint">
        Balance due = opening balance&nbsp;+ invoices issued &minus; payments received &minus; credit notes applied. Invoices in
        draft or cancelled state are excluded, each figure is converted to base currency at the rate on its own document, and
        settlements are counted once even when their invoice is fully paid.
        @if (request()->filled('from') || request()->filled('to'))
            With a date filter, the three activity figures cover the selected period only; the opening balance is always included and items dated before the period are not re-opened.
        @endif
    </p>
</x-app-layout>
