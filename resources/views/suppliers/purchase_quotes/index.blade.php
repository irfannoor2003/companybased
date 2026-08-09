<x-app-layout :pageTitle="'Purchase quotes'">
    <x-slot name="header">
        <x-page-header
            title="Purchase quotes"
            description="Supplier price quotes, from request through to conversion."
            icon="document"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('suppliers.purchase_quotes.export'))
                    <x-export route="suppliers.purchase_quotes.export" />
                @endif
                @if (auth()->user()->can('suppliers.purchase_quotes.create'))
                    <x-button href="{{ route('suppliers.purchase_quotes.create') }}" icon="plus">New quote</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('suppliers.purchase_quotes.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Quote number…" leadingIcon="search"
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
                    @foreach (\App\Models\PurchaseQuote::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'supplier', 'status']))
                    <x-button href="{{ route('suppliers.purchase_quotes.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($quotes->isEmpty())
            <x-empty-state icon="document" title="No purchase quotes found" description="Request a price quote from a supplier." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Quote</th>
                            <th>Supplier</th>
                            <th>Issued</th>
                            <th>Valid until</th>
                            <th>Status</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($quotes as $quote)
                            <tr>
                                <td>
                                    <a href="{{ route('suppliers.purchase_quotes.edit', $quote) }}" class="font-medium text-ink hover:text-primary">{{ $quote->number }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $quote->supplier?->company_name }}</td>
                                <td class="text-ink-soft">{{ $quote->issue_date?->format('Y-m-d') }}</td>
                                <td class="text-ink-soft">{{ $quote->valid_until?->format('Y-m-d') ?: '—' }}</td>
                                <td><x-suppliers.status-badge :status="$quote->status" /></td>
                                <td class="text-right font-medium text-ink">{{ money($quote->total, $quote->currency) }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if (auth()->user()->can('suppliers.purchase_quotes.convert') && ! $quote->isConverted())
                                            <form method="POST" action="{{ route('suppliers.purchase_quotes.convert', $quote) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="btn-ghost btn-icon btn-sm" title="Convert to purchase order">
                                                    <x-icon name="refresh" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ route('suppliers.purchase_quotes.edit', $quote) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('suppliers.purchase_quotes.delete'))
                                            <form method="POST" action="{{ route('suppliers.purchase_quotes.destroy', $quote) }}"
                                                onsubmit="return confirm('Delete purchase quote {{ $quote->number }}?');">
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

        @if ($quotes->hasPages())
            <div class="px-5 py-4">
                {{ $quotes->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
