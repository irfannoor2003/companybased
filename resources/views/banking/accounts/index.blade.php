<x-app-layout :pageTitle="'Bank accounts'">
    <x-slot name="header">
        <x-page-header title="Bank accounts" description="Bank and cash accounts with live balances." icon="banking">
            <x-slot name="actions">
                @if (auth()->user()->can('banking.accounts.export'))
                    <x-export route="banking.accounts.export" />
                @endif
                @if (auth()->user()->can('banking.accounts.create'))
                    <x-button href="{{ route('banking.accounts.create') }}" icon="plus">New account</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-card label="Total balances" :value="money($totalBalance)" icon="banking" tone="primary" />
        <x-stat-card label="Cash accounts" :value="money($cashBalance)" icon="cashflow" tone="success" />
        <x-stat-card label="Active accounts" :value="$accounts->where('is_active', true)->count()" icon="check-circle" tone="info" />
        <x-stat-card label="Accounts listed" :value="$accounts->total()" icon="document" tone="neutral" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('banking.accounts.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Name, bank, account no…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-select name="type" label="Type" size="sm">
                        <option value="">Any type</option>
                        @foreach (\App\Models\BankAccount::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-36">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        <option value="active" @selected(request('status') === 'active')>Active</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'type', 'status']))
                        <x-button href="{{ route('banking.accounts.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($accounts->isEmpty())
                <x-empty-state icon="banking" title="No bank accounts" description="Create an account to start tracking balances and transactions." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Bank / branch</th>
                                <th>Type</th>
                                <th class="text-right">Balance</th>
                                <th class="text-right">Transactions</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                <tr>
                                    <td>
                                        <a href="{{ route('banking.accounts.show', $account) }}" class="font-medium text-ink hover:text-primary">
                                            {{ $account->name }}
                                        </a>
                                        @if ($account->account_number)
                                            <p class="text-xs text-ink-faint">{{ $account->account_number }}</p>
                                        @endif
                                    </td>
                                    <td class="text-ink-soft">{{ $account->bank_name ?: '—' }}{{ $account->branch ? ' · '.$account->branch : '' }}</td>
                                    <td><span class="text-ink-soft">{{ ucfirst($account->account_type) }}</span></td>
                                    <td class="text-right font-medium {{ $account->balance() < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-ink' }}">{{ money($account->balance(), $account->currency) }}</td>
                                    <td class="text-right text-ink-soft">{{ $account->transactions_count }}</td>
                                    <td><x-banking.status-badge :status="$account->is_active ? 'active' : 'inactive'" /></td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('banking.accounts.edit'))
                                            <div class="flex items-center justify-end gap-1">
                                                <a href="{{ route('banking.accounts.show', $account) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                    <x-icon name="eye" class="size-4" />
                                                </a>
                                                <a href="{{ route('banking.accounts.edit', $account) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                    <x-icon name="edit" class="size-4" />
                                                </a>
                                                @if (auth()->user()->can('banking.accounts.delete'))
                                                    <form method="POST" action="{{ route('banking.accounts.destroy', $account) }}"
                                                        onsubmit="return confirm('Delete account {{ $account->name }}?');">
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

            @if ($accounts->hasPages())
                <div class="px-5 py-4">
                    {{ $accounts->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
