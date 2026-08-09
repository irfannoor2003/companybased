<x-app-layout :pageTitle="'Supplier payments'">
    <x-slot name="header">
        <x-page-header
            title="Supplier payments"
            description="Payments made to suppliers against their invoices."
            icon="money"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('suppliers.supplier_payments.export'))
                    <x-export route="suppliers.supplier_payments.export" />
                @endif
                @if (auth()->user()->can('suppliers.supplier_payments.create'))
                    <x-button href="{{ route('suppliers.supplier_payments.create') }}" icon="plus">New payment</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('suppliers.supplier_payments.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Payment number…" leadingIcon="search"
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
            <div class="w-40">
                <x-select name="method" label="Method" size="sm">
                    <option value="">Any method</option>
                    @foreach (\App\Models\SupplierPayment::methodOptions() as $method)
                        <option value="{{ $method }}" @selected(request('method') === $method)>{{ ucwords(str_replace('_', ' ', $method)) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'supplier', 'method']))
                    <x-button href="{{ route('suppliers.supplier_payments.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($payments->isEmpty())
            <x-empty-state icon="money" title="No supplier payments found" description="Record a payment made to a supplier." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Payment</th>
                            <th>Supplier</th>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td>
                                    <a href="{{ route('suppliers.supplier_payments.edit', $payment) }}" class="font-medium text-ink hover:text-primary">{{ $payment->number }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $payment->supplier?->company_name }}</td>
                                <td class="text-ink-soft">{{ $payment->invoice?->number ?: '—' }}</td>
                                <td class="text-ink-soft">{{ $payment->payment_date?->format('Y-m-d') }}</td>
                                <td class="text-ink-soft">{{ ucwords(str_replace('_', ' ', $payment->method)) }}</td>
                                <td class="text-right font-medium text-ink">{{ money($payment->amount, $payment->currency) }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('suppliers.supplier_payments.edit', $payment) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('suppliers.supplier_payments.delete'))
                                            <form method="POST" action="{{ route('suppliers.supplier_payments.destroy', $payment) }}"
                                                onsubmit="return confirm('Delete payment {{ $payment->number }}?');">
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

        @if ($payments->hasPages())
            <div class="px-5 py-4">
                {{ $payments->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
