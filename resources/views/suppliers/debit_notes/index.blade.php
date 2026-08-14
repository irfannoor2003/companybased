<x-app-layout :pageTitle="'Debit notes'">
    <x-slot name="header">
        <x-page-header
            title="Debit notes"
            description="Reductions claimed against supplier invoices."
            icon="credit"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('suppliers.debit_notes.export'))
                    <x-export route="suppliers.debit_notes.export" />
                @endif
                @if (auth()->user()->can('suppliers.debit_notes.create'))
                    <x-button href="{{ route('suppliers.debit_notes.create') }}" icon="plus">New debit note</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('suppliers.debit_notes.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Debit note number…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-56">
                <x-select name="supplier" label="Supplier" size="sm">
                    <option value="">All suppliers</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier') == $supplier->id)>{{ $supplier->company_name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'supplier']))
                    <x-button href="{{ route('suppliers.debit_notes.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($debitNotes->isEmpty())
            <x-empty-state icon="credit" title="No debit notes found" description="Issue a debit note against a supplier invoice to reduce a payable." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Debit note</th>
                            <th>Supplier</th>
                            <th>Invoice</th>
                            <th>Issued</th>
                            <th>Reason</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($debitNotes as $note)
                            <tr>
                                <td>
                                    <a href="{{ route('suppliers.debit_notes.edit', $note) }}" class="font-medium text-ink hover:text-primary">{{ $note->number }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $note->supplier?->company_name }}</td>
                                <td class="text-ink-soft">{{ $note->invoice?->number ?: '—' }}</td>
                                <td class="text-ink-soft">{{ $note->issue_date?->format('Y-m-d') }}</td>
                                <td class="max-w-xs truncate text-ink-soft">{{ $note->reason ?: '—' }}</td>
                                <td class="text-right font-medium text-ink">{{ money($note->total, $note->currency) }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if (auth()->user()->can('suppliers.debit_notes.view'))
                                            <a href="{{ route('suppliers.debit_notes.show', $note) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                        @endif
                                        <a href="{{ route('suppliers.debit_notes.edit', $note) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('suppliers.debit_notes.delete'))
                                            <form method="POST" action="{{ route('suppliers.debit_notes.destroy', $note) }}"
                                                onsubmit="return confirm('Delete debit note {{ $note->number }}?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete">
                                                    <x-icon name="trash" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($debitNotes->hasPages())
            <div class="px-5 py-4">
                {{ $debitNotes->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
