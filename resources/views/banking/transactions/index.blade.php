<x-app-layout :pageTitle="'Bank transactions'">
    <x-slot name="header">
        <x-page-header title="Bank transactions" description="Deposits and withdrawals across all accounts." icon="money">
            <x-slot name="actions">
                @if (auth()->user()->can('banking.transactions.export'))
                    <x-export route="banking.transactions.export" />
                @endif
                @if (auth()->user()->can('banking.transactions.create'))
                    <x-button href="{{ route('banking.transactions.create') }}" icon="plus">New transaction</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('banking.transactions.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[200px] flex-1">
                <x-input name="search" label="Search" placeholder="Number, counterparty, reference…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-52">
                <x-select name="account" label="Account" size="sm">
                    <option value="">All accounts</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected(request('account') == $account->id)>{{ $account->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-36">
                <x-select name="type" label="Type" size="sm">
                    <option value="">Any type</option>
                    <option value="deposit" @selected(request('type') === 'deposit')>Deposit</option>
                    <option value="withdrawal" @selected(request('type') === 'withdrawal')>Withdrawal</option>
                    <option value="transfer_in" @selected(request('type') === 'transfer_in')>Transfer in</option>
                    <option value="transfer_out" @selected(request('type') === 'transfer_out')>Transfer out</option>
                </x-select>
            </div>
            <div>
                <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
            </div>
            <div>
                <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'account', 'type', 'from', 'to']))
                    <x-button href="{{ route('banking.transactions.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($transactions->isEmpty())
            <x-empty-state icon="money" title="No transactions" description="Record a deposit or withdrawal to get started." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Date</th>
                            <th>Account</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Reconciled</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transactions as $transaction)
                            <tr>
                                <td><span class="font-mono text-xs font-medium text-primary">{{ $transaction->number }}</span></td>
                                <td class="text-ink-soft">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                <td class="text-ink">{{ $transaction->account?->name }}</td>
                                <td>
                                    <span class="inline-flex items-center gap-1 text-xs">
                                        @if ($transaction->isCredit())
                                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                        @else
                                            <span class="size-1.5 rounded-full bg-rose-500"></span>
                                        @endif
                                        {{ ucfirst(str_replace('_', ' ', $transaction->type)) }}
                                    </span>
                                </td>
                                <td class="text-ink-soft">{{ $transaction->description ?? $transaction->counterparty ?? '—' }}</td>
                                <td>
                                    @if ($transaction->is_reconciled)
                                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Yes</span>
                                    @else
                                        <span class="text-xs text-ink-faint">No</span>
                                    @endif
                                </td>
                                <td class="text-right font-medium {{ $transaction->isDebit() ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    {{ $transaction->isDebit() ? '−' : '+' }}{{ money($transaction->amount, $transaction->account?->currency) }}
                                </td>
                                <td class="text-right">
                                    @if (auth()->user()->can('banking.transactions.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('banking.transactions.edit', $transaction) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('banking.transactions.delete'))
                                                <form method="POST" action="{{ route('banking.transactions.destroy', $transaction) }}"
                                                    onsubmit="return confirm('Delete transaction {{ $transaction->number }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete">
                                                        <x-icon name="trash" class="size-4" />
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
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
</x-app-layout>
