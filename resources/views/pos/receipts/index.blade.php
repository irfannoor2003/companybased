<x-app-layout :pageTitle="'POS Receipts'">
    <x-slot name="header">
        <x-page-header title="Receipts" description="Transaction slip history from the till." icon="document">
            <x-slot name="actions">
                @if (auth()->user()->can('pos.receipts.export'))
                    <x-export route="pos.receipts.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('pos._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('pos.receipts.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Receipt number…" leadingIcon="search" value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'from', 'to']))
                        <x-button href="{{ route('pos.receipts.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($sales->isEmpty())
                <x-empty-state icon="document" title="No receipts" description="Receipts appear here after a sale is completed." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Receipt</th>
                                <th>Shift</th>
                                <th>Cashier</th>
                                <th>Items</th>
                                <th class="text-right">Subtotal</th>
                                <th class="text-right">Total</th>
                                <th>Sold at</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sales as $sale)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $sale->receipt_number }}</td>
                                    <td class="font-mono text-ink-soft">{{ $sale->shift_number ?? '—' }}</td>
                                    <td class="text-ink-soft">{{ $sale->shift?->opener?->name ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $sale->items_count }}</td>
                                    <td class="text-right text-ink-soft">{{ money($sale->subtotal) }}</td>
                                    <td class="text-right font-semibold text-ink">{{ money($sale->total) }}</td>
                                    <td class="text-ink-soft">{{ $sale->sold_at?->format('Y-m-d H:i') }}</td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('pos.receipts.show'))
                                            <a href="{{ route('pos.receipts.show', $sale->receipt_number) }}" class="btn-ghost btn-icon btn-sm" title="View receipt">
                                                <x-icon name="document" class="size-4" />
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($sales->hasPages())
                <div class="px-5 py-4">
                    {{ $sales->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
