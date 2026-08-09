<x-app-layout :pageTitle="'Supplier ledger — '.$supplier->company_name">
    <x-slot name="header">
        <x-page-header title="{{ $supplier->company_name }}" description="Supplier ledger" icon="report">
            <x-slot name="actions">
                @if (auth()->user()->can('suppliers.supplier_ledger.export'))
                    <x-button href="{{ route('suppliers.supplier_ledger.export', $supplier) }}" variant="secondary" icon="export">Export CSV</x-button>
                @endif
                <x-button href="{{ route('suppliers.suppliers.show', $supplier) }}" variant="secondary" icon="arrow-left">Supplier</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-card title="Billed">
            <p class="text-xl font-semibold text-ink">{{ money($ledger->where('type', 'Invoice')->sum('credit'), $supplier->currency) }}</p>
        </x-card>
        <x-card title="Paid & debit notes">
            <p class="text-xl font-semibold text-emerald-600 dark:text-emerald-400">{{ money($ledger->sum('debit') ?? 0, $supplier->currency) }}</p>
        </x-card>
        <x-card title="Closing balance">
            <p class="text-xl font-semibold {{ $supplier->balance() > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                {{ money($supplier->balance(), $supplier->currency) }}
            </p>
        </x-card>
    </div>

    <x-card :padding="false" class="mt-6">
        <div class="border-b border-line px-5 py-4">
            <h2 class="text-sm font-semibold text-ink">Ledger</h2>
        </div>

        @if ($ledger->isEmpty())
            <x-empty-state icon="report" title="No activity" description="No invoices, payments or debit notes on record." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                            <th class="text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ledger as $entry)
                            <tr>
                                <td class="text-ink-soft">{{ $entry['date']?->format('Y-m-d') }}</td>
                                <td>
                                    <x-badge color="{{ $entry['type'] === 'Invoice' ? 'info' : 'success' }}" dot>{{ $entry['type'] }}</x-badge>
                                </td>
                                <td>
                                    @if (! empty($entry['url']))
                                        <a href="{{ $entry['url'] }}" class="font-medium text-primary hover:underline">{{ $entry['reference'] }}</a>
                                    @else
                                        <span class="text-ink-soft">{{ $entry['reference'] }}</span>
                                    @endif
                                </td>
                                <td class="text-right text-rose-600 dark:text-rose-400">{{ $entry['debit'] !== null ? money($entry['debit'], $supplier->currency) : '—' }}</td>
                                <td class="text-right text-emerald-600 dark:text-emerald-400">{{ $entry['credit'] !== null ? money($entry['credit'], $supplier->currency) : '—' }}</td>
                                <td class="text-right font-medium text-ink">{{ money($entry['balance'], $supplier->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-app-layout>
