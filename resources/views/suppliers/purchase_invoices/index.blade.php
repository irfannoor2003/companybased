<x-app-layout :pageTitle="'Purchase invoices'">
    <x-slot name="header">
        <x-page-header
            title="Purchase invoices"
            description="Supplier invoices received for payment."
            icon="invoice"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('suppliers.purchase_invoices.export'))
                    <x-export route="suppliers.purchase_invoices.export" />
                @endif
                @if (auth()->user()->can('suppliers.purchase_invoices.create'))
                    <x-button href="{{ route('suppliers.purchase_invoices.create') }}" icon="plus">New purchase invoice</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('suppliers.purchase_invoices.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Invoice number…" leadingIcon="search"
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
            <div class="w-44">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\PurchaseInvoice::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'supplier', 'status']))
                    <x-button href="{{ route('suppliers.purchase_invoices.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($invoices->isEmpty())
            <x-empty-state icon="invoice" title="No purchase invoices found" description="Receive a supplier invoice to record a payable." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Supplier</th>
                            <th>Issued</th>
                            <th>Due</th>
                            <th>Status</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Balance</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td>
                                    <a href="{{ route('suppliers.purchase_invoices.edit', $invoice) }}" class="font-medium text-ink hover:text-primary">{{ $invoice->number }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $invoice->supplier?->company_name }}</td>
                                <td class="text-ink-soft">{{ $invoice->issue_date?->format('Y-m-d') }}</td>
                                <td class="text-ink-soft">{{ $invoice->due_date?->format('Y-m-d') ?: '—' }}</td>
                                <td><x-suppliers.status-badge :status="$invoice->status" /></td>
                                <td class="text-right font-medium text-ink">{{ money($invoice->total, $invoice->currency) }}</td>
                                <td class="text-right">
                                    @if ($invoice->balance() > 0)
                                        <span class="font-medium text-rose-600 dark:text-rose-400">{{ money($invoice->balance(), $invoice->currency) }}</span>
                                    @else
                                        <span class="font-medium text-emerald-600 dark:text-emerald-400">{{ money($invoice->balance(), $invoice->currency) }}</span>
                                    @endif
                                </td>
                                    <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if (auth()->user()->can('suppliers.purchase_invoices.view'))
                                            <a href="{{ route('suppliers.purchase_invoices.show', $invoice) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                        @endif
                                        <a href="{{ route('suppliers.purchase_invoices.edit', $invoice) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('suppliers.purchase_invoices.delete'))
                                            <form method="POST" action="{{ route('suppliers.purchase_invoices.destroy', $invoice) }}"
                                                onsubmit="return confirm('Delete purchase invoice {{ $invoice->number }}?');">
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

        @if ($invoices->hasPages())
            <div class="px-5 py-4">
                {{ $invoices->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
