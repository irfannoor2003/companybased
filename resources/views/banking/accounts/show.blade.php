<x-app-layout :pageTitle="$account->name">
    <x-slot name="header">
        <x-page-header title="{{ $account->name }}" description="{{ $account->bank_name ?: 'Bank account' }} · {{ $account->account_number ?? ucfirst($account->account_type) }}" icon="banking">
            <x-slot name="actions">
                @if (auth()->user()->can('banking.transactions.create'))
                    <x-button href="{{ route('banking.transactions.create', ['account' => $account->id]) }}" variant="secondary" icon="plus">New transaction</x-button>
                @endif
                @if (auth()->user()->can('banking.transfers.create'))
                    <x-button href="{{ route('banking.transfers.create') }}" variant="secondary" icon="arrow-right">Transfer</x-button>
                @endif
                @if (auth()->user()->can('banking.reconciliations.create'))
                    <x-button href="{{ route('banking.reconciliations.create') }}" variant="secondary" icon="check-circle">Reconcile</x-button>
                @endif
                @if (auth()->user()->can('banking.accounts.edit'))
                    <x-button href="{{ route('banking.accounts.edit', $account) }}" icon="edit">Edit</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Current balance" :value="money($account->balance(), $account->currency)" icon="banking" tone="primary" />
        <x-stat-card label="Reconciled balance" :value="money($account->clearedBalance(), $account->currency)" icon="check-circle" tone="success" />
        <x-stat-card label="Deposits" :value="money($account->transactions->whereIn('type', ['deposit', 'transfer_in'])->sum('amount'), $account->currency)" icon="arrow-down" tone="success" />
        <x-stat-card label="Withdrawals" :value="money($account->transactions->whereIn('type', ['withdrawal', 'transfer_out'])->sum('amount'), $account->currency)" icon="arrow-up" tone="danger" />
    </div>

    <div class="mt-6">
        <x-card title="Recent transactions" :padding="false">
            @if ($account->transactions->isEmpty())
                <x-empty-state icon="money" title="No transactions" description="Record a transaction or transfer to see activity here." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($account->transactions as $transaction)
                                <tr>
                                    <td><span class="font-mono text-xs font-medium text-primary">{{ $transaction->number }}</span></td>
                                    <td class="text-ink-soft">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                    <td class="text-ink-soft">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</td>
                                    <td class="text-ink">{{ $transaction->description ?? $transaction->counterparty ?? '—' }}</td>
                                    <td class="text-right font-medium {{ $transaction->isDebit() ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                        {{ $transaction->isDebit() ? '−' : '+' }}{{ money($transaction->amount, $account->currency) }}
                                    </td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('banking.transactions.edit'))
                                            <a href="{{ route('banking.transactions.edit', $transaction) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
