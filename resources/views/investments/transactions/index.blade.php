<x-app-layout :pageTitle="'Investment Transactions'">
    <x-slot name="header">
        <x-page-header title="Transactions" description="Buys and sells across the investment portfolio." icon="money">
            <x-slot name="actions">
                @if (auth()->user()->can('investments.transactions.export'))
                    <x-export route="investments.transactions.export" />
                @endif
                @if (auth()->user()->can('investments.transactions.create'))
                    <x-button href="{{ route('investments.transactions.create') }}" icon="plus">Record transaction</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('investments.transactions.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Investment code or name…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-48">
                    <x-select name="investment_id" label="Investment" size="sm">
                        <option value="">All holdings</option>
                        @foreach ($investments as $investment)
                            <option value="{{ $investment->id }}" @selected(request('investment_id') == $investment->id)>{{ $investment->code }} — {{ $investment->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-36">
                    <x-select name="type" label="Type" size="sm">
                        <option value="">Any</option>
                        <option value="buy" @selected(request('type') === 'buy')>Buy</option>
                        <option value="sell" @selected(request('type') === 'sell')>Sell</option>
                    </x-select>
                </div>
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'investment_id', 'type', 'from', 'to']))
                        <x-button href="{{ route('investments.transactions.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($transactions->isEmpty())
                <x-empty-state icon="money" title="No transactions" description="Record a buy or sell to get started." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Investment</th>
                                <th>Type</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Unit price</th>
                                <th class="text-right">Fees</th>
                                <th class="text-right">Total</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($transactions as $transaction)
                                <tr>
                                    <td class="text-ink-soft">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                    <td>
                                        <span class="font-mono text-xs text-ink-faint">{{ $transaction->investment?->code }}</span>
                                        <span class="block text-ink-soft">{{ $transaction->investment?->name }}</span>
                                    </td>
                                    <td>
                                        <x-badge :color="$transaction->type === 'buy' ? 'success' : 'danger'">{{ ucfirst($transaction->type) }}</x-badge>
                                    </td>
                                    <td class="text-right text-ink-soft">{{ number_format((float) $transaction->quantity, 2) }}</td>
                                    <td class="text-right text-ink-soft">{{ money($transaction->unit_price, $transaction->investment?->currency) }}</td>
                                    <td class="text-right text-ink-faint">{{ money($transaction->fees, $transaction->investment?->currency) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($transaction->total, $transaction->investment?->currency) }}</td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('investments.transactions.edit'))
                                            <a href="{{ route('investments.transactions.edit', $transaction) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('investments.transactions.delete'))
                                            <form method="POST" action="{{ route('investments.transactions.destroy', $transaction) }}" class="inline" onsubmit="return confirm('Delete this transaction?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost btn-icon btn-sm text-danger" title="Delete">
                                                    <x-icon name="trash" class="size-4" />
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($transactions->hasPages())
                <div class="px-5 py-4">
                    {{ $transactions->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>