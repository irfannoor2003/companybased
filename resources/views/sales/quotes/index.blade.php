<x-app-layout :pageTitle="'Quotes'">
    <x-slot name="header">
        <x-page-header
            title="Quotes"
            description="Draft, send, accept and convert quotes into sales orders."
            icon="document"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('sales.quotes.export'))
                    <x-export route="sales.quotes.export" />
                @endif
                @if (auth()->user()->can('sales.quotes.create'))
                    <x-button href="{{ route('sales.quotes.create') }}" icon="plus">New quote</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('sales.quotes.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Quote number…" leadingIcon="search"
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
            <div class="w-44">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\SalesQuote::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'customer', 'status']))
                    <x-button href="{{ route('sales.quotes.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($quotes->isEmpty())
            <x-empty-state icon="document" title="No quotes found" description="Create a quote to start the sales pipeline." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Quote</th>
                            <th>Customer</th>
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
                                    <a href="{{ route('sales.quotes.edit', $quote) }}" class="font-medium text-ink hover:text-primary">{{ $quote->number }}</a>
                                </td>
                                <td class="text-ink-soft">{{ $quote->customer?->company_name }}</td>
                                <td class="text-ink-soft">{{ $quote->issue_date?->format('Y-m-d') }}</td>
                                <td class="text-ink-soft">{{ $quote->valid_until?->format('Y-m-d') ?: '—' }}</td>
                                <td><x-sales.status-badge :status="$quote->status" /></td>
                                <td class="text-right font-medium text-ink">{{ money($quote->total, $quote->currency) }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if (auth()->user()->can('sales.quotes.view'))
                                            <a href="{{ route('sales.quotes.show', $quote) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                        @endif
                                        <a href="{{ route('sales.quotes.edit', $quote) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                            <x-icon name="edit" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('sales.quotes.convert') && ! $quote->isConverted())
                                            <form method="POST" action="{{ route('sales.quotes.convert', $quote) }}"
                                                onsubmit="return confirm('Convert quote {{ $quote->number }} into a sales order?');">
                                                @csrf
                                                <button type="submit" class="btn-ghost btn-icon btn-sm" title="Convert to order">
                                                    <x-icon name="refresh" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                        @if (auth()->user()->can('sales.quotes.delete'))
                                            <form method="POST" action="{{ route('sales.quotes.destroy', $quote) }}"
                                                onsubmit="return confirm('Delete quote {{ $quote->number }}?');">
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
