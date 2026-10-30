<x-app-layout :pageTitle="'Debit Notes Report'">
    <x-slot name="header">
        <x-page-header title="Debit Notes Report" description="Debit notes raised, applied and outstanding" icon="invoice">
            <x-slot name="actions">
                <x-button href="{{ route('reports.registers.debit_notes.export', request()->query()) }}" variant="secondary" icon="download">Export CSV</x-button>
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
            <div class="w-56">
                <x-select name="supplier_id" label="Supplier">
                    <option value="">All suppliers</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}" @selected(request('supplier_id') == $s->id)>{{ $s->company_name }}</option>
                    @endforeach
                </x-select>
            </div>
            <x-button type="submit" icon="search">Filter</x-button>
            @if (request()->hasAny(['from', 'to', 'supplier_id']))
                <x-button href="{{ route('reports.registers.debit_notes') }}" variant="ghost">Clear</x-button>
            @endif
        </form>
    </x-card>

    <x-card title="Debit notes" class="mt-6" :padding="false">
        @if ($rows->count())
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-surface-muted text-left text-xs font-medium uppercase tracking-wider text-ink-soft">
                        <tr>
                            <th class="px-4 py-3">Number</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Supplier</th>
                            <th class="px-4 py-3">Reason</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Applied</th>
                            <th class="px-4 py-3 text-right">Unapplied</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($rows as $note)
                            <tr class="hover:bg-surface-muted/50">
                                <td class="whitespace-nowrap px-4 py-3 font-medium">
                                    @if (auth()->user()->can('suppliers.debit_notes.view'))
                                        <a href="{{ route('suppliers.debit_notes.show', $note) }}" class="text-primary-ink hover:underline">{{ $note->number }}</a>
                                    @else
                                        {{ $note->number }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $note->issue_date }}</td>
                                <td class="px-4 py-3">{{ $note->supplier?->company_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $note->reason ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium">{{ money($note->total) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($note->applied_amount) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right {{ ($note->total - $note->applied_amount) > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                    {{ money($note->total - $note->applied_amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-line bg-surface-muted font-medium">
                        <tr>
                            <td class="px-4 py-3" colspan="4">Total ({{ $rows->count() }} notes)</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($total) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($appliedTotal) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">{{ money($unappliedTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <x-empty-state icon="invoice" title="No debit notes found" description="No debit notes match your filters." />
        @endif
    </x-card>

    <p class="mt-3 text-xs text-ink-faint">
        Unapplied = the balance still available to debit against future purchase invoices. It reduces what you owe the supplier
        once applied.
    </p>
</x-app-layout>
