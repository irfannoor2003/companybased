<x-app-layout :pageTitle="'Suppliers Report'">
    <x-slot name="header">
        <x-page-header title="Suppliers Report" description="Amounts owed to each supplier" icon="money">
            <x-slot name="actions">
                <x-button href="{{ route('reports.registers.suppliers.export', request()->query()) }}" variant="secondary" icon="download">Export CSV</x-button>
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
                <x-button href="{{ route('reports.registers.suppliers') }}" variant="ghost">Clear</x-button>
            @endif
        </form>
    </x-card>

    <x-card title="Payables" class="mt-6" :padding="false">
        @if (count($rows))
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-surface-muted text-left text-xs font-medium uppercase tracking-wider text-ink-soft">
                        <tr>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Supplier</th>
                            <th class="px-4 py-3 text-right">Invoiced</th>
                            <th class="px-4 py-3 text-right">Payments made</th>
                            <th class="px-4 py-3 text-right">Debit notes applied</th>
                            <th class="px-4 py-3 text-right">Balance due</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-surface-muted/50">
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $row['code'] ?: '—' }}</td>
                                <td class="px-4 py-3 font-medium">{{ $row['name'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($row['invoiced']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right {{ $row['paid_out'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : '' }}">{{ money($row['paid_out']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right {{ $row['credited'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : '' }}">{{ money($row['credited']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium {{ $row['balance'] < 0 ? 'text-sky-600' : '' }}">{{ money($row['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-line bg-surface-muted font-medium">
                        <tr>
                            <td class="px-4 py-3" colspan="2">Total</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($totals['invoiced']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($totals['paid_out']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($totals['credited']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($totals['balance']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <x-empty-state icon="money" title="No suppliers yet" description="Add suppliers to see their balances here." />
        @endif
    </x-card>

    <p class="mt-3 text-xs text-ink-faint">
        Balance due = purchase invoices issued &minus; payments made &minus; debit notes applied. Suppliers carry no opening
        balance in this system. Invoices in draft or cancelled state are excluded, each figure is converted to base currency at
        the rate on its own document, and settlements are counted once even when their invoice is fully paid.
        @if (request()->filled('from') || request()->filled('to'))
            With a date filter, the three activity figures cover the selected period only.
        @endif
    </p>
</x-app-layout>
