<x-app-layout :pageTitle="'Withholding tax receipts'">
    <x-slot name="header">
        <x-page-header
            title="Withholding tax receipts"
            description="Tax withheld from supplier/customer payments, documented per receipt."
            icon="tax"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('sales.withholding_tax_receipts.export'))
                    <x-export route="sales.withholding_tax_receipts.export" />
                @endif
                @if (auth()->user()->can('sales.withholding_tax_receipts.create'))
                    <x-button href="{{ route('sales.withholding_tax_receipts.create') }}" icon="plus">New receipt</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('sales.withholding_tax_receipts.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Receipt number…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-56">
                <x-select name="customer" label="Customer" size="sm">
                    <option value="">All customers</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(request('customer') == $customer->id)>{{ $customer->company_name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'customer']))
                    <x-button href="{{ route('sales.withholding_tax_receipts.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($receipts->isEmpty())
            <x-empty-state icon="tax" title="No withholding tax receipts" description="Record a receipt whenever tax is withheld from a payment." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Rate</th>
                            <th class="text-right">Tax withheld</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipts as $receipt)
                            <tr>
                                <td>
                                    <a href="{{ route('sales.withholding_tax_receipts.edit', $receipt) }}" class="font-medium text-ink hover:text-primary">{{ $receipt->number }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $receipt->customer?->company_name }}</td>
                                <td class="text-ink-soft">{{ $receipt->receipt_date?->format('Y-m-d') }}</td>
                                <td class="text-right text-ink">{{ money($receipt->amount, $receipt->currency) }}</td>
                                <td class="text-right text-ink-soft">{{ $receipt->tax_rate_percent }}%</td>
                                <td class="text-right font-medium text-ink">{{ money($receipt->tax_amount, $receipt->currency) }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if (auth()->user()->can('sales.withholding_tax_receipts.view'))
                                            <a href="{{ route('sales.withholding_tax_receipts.show', $receipt) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                        @endif
                                        <a href="{{ route('sales.withholding_tax_receipts.pdf', $receipt) }}" class="btn-ghost btn-icon btn-sm" title="Download PDF">
                                            <x-icon name="download" class="size-4" />
                                        </a>
                                        <a href="{{ route('sales.withholding_tax_receipts.edit', $receipt) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('sales.withholding_tax_receipts.delete'))
                                            <form method="POST" action="{{ route('sales.withholding_tax_receipts.destroy', $receipt) }}"
                                                onsubmit="return confirm('Delete withholding tax receipt {{ $receipt->number }}?');">
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

        @if ($receipts->hasPages())
            <div class="px-5 py-4">
                {{ $receipts->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
