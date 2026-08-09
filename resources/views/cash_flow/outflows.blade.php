<x-app-layout :pageTitle="'Cash outflows'">
    <x-slot name="header">
        <x-page-header title="Cash outflows" description="Supplier payments and bank withdrawals recorded within the period." icon="arrow-up">
            <x-slot name="actions">
                @if (auth()->user()->can('cash_flow.outflows.export'))
                    <x-export route="cash_flow.outflows.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('cash_flow._tabs')

    <x-card :padding="false" class="mt-6">
        <form method="GET" action="{{ route('cash_flow.outflows') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div>
                <x-input name="from" label="From" type="date" value="{{ $from }}" size="sm" />
            </div>
            <div>
                <x-input name="to" label="To" type="date" value="{{ $to }}" size="sm" />
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['from', 'to']))
                    <x-button href="{{ route('cash_flow.outflows') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($rows->isEmpty())
            <x-empty-state icon="arrow-up" title="No outflows" description="No supplier payments or bank withdrawals in this period." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Source</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td class="text-ink-soft">{{ $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : $row['date'] }}</td>
                                <td class="text-ink">{{ $row['source'] }}</td>
                                <td><span class="font-mono text-xs text-ink-soft">{{ $row['ref'] ?? '—' }}</span></td>
                                <td class="text-ink-soft">{{ $row['desc'] ?? '—' }}</td>
                                <td class="text-right font-medium text-rose-600 dark:text-rose-400">−{{ money($row['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-right font-semibold text-ink">Total</td>
                            <td class="text-right font-semibold text-rose-600 dark:text-rose-400">−{{ money($rows->sum('amount')) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-card>
</x-app-layout>
