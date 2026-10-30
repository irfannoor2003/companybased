<x-app-layout :pageTitle="'Credit Notes Report'">
    <x-slot name="header">
        <x-page-header title="Credit Notes Report" description="Credit notes issued, applied and outstanding" icon="credit">
            <x-slot name="actions">
                <x-button href="{{ route('reports.registers.credit_notes.export', request()->query()) }}" variant="secondary" icon="download">Export CSV</x-button>
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
                <x-select name="customer_id" label="Customer">
                    <option value="">All customers</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected(request('customer_id') == $c->id)>{{ $c->company_name }}</option>
                    @endforeach
                </x-select>
            </div>
            <x-button type="submit" icon="search">Filter</x-button>
            @if (request()->hasAny(['from', 'to', 'customer_id']))
                <x-button href="{{ route('reports.registers.credit_notes') }}" variant="ghost">Clear</x-button>
            @endif
        </form>
    </x-card>

    <x-card title="Credit notes" class="mt-6" :padding="false">
        @if ($rows->count())
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-surface-muted text-left text-xs font-medium uppercase tracking-wider text-ink-soft">
                        <tr>
                            <th class="px-4 py-3">Number</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Customer</th>
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
                                    @if (auth()->user()->can('sales.credit_notes.view'))
                                        <a href="{{ route('sales.credit_notes.show', $note) }}" class="text-primary-ink hover:underline">{{ $note->number }}</a>
                                    @else
                                        {{ $note->number }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-ink-soft">{{ $note->issue_date }}</td>
                                <td class="px-4 py-3">{{ $note->customer?->company_name ?? '—' }}</td>
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
            <x-empty-state icon="credit" title="No credit notes found" description="No credit notes match your filters." />
        @endif
    </x-card>

    <p class="mt-3 text-xs text-ink-faint">
        Unapplied = the balance still available to credit against future invoices. An unapplied figure above zero is money your
        customer holds with you, not money you owe.
    </p>
</x-app-layout>
