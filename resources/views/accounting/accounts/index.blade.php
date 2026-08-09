<x-app-layout :pageTitle="'Chart of accounts'">
    <x-slot name="header">
        <x-page-header title="Accounting" description="Chart of accounts, journals, bills, tax and budgets." icon="accounting">
            <x-slot name="actions">
                @if (auth()->user()->can('accounting.chart_of_accounts.export'))
                    <x-export route="accounting.accounts.export" />
                @endif
                @if (auth()->user()->can('accounting.chart_of_accounts.create'))
                    <x-button href="{{ route('accounting.accounts.create') }}" icon="plus">New account</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($totals as $type => $balance)
            <x-stat-card :label="\App\Models\Account::typeLabel($type).'s'" :value="money($balance)" icon="database" :tone="$type === 'expense' ? 'warning' : ($type === 'revenue' ? 'success' : 'info')" />
        @endforeach
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('accounting.accounts.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Code or name…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-select name="type" label="Type" size="sm">
                        <option value="">Any type</option>
                        @foreach (\App\Models\Account::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ \App\Models\Account::typeLabel($type) }}</option>
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
                        <x-button href="{{ route('accounting.accounts.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($accounts->isEmpty())
                <x-empty-state icon="database" title="No accounts" description="Build your chart of accounts to start posting journals." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Sub type</th>
                                <th>Parent</th>
                                <th class="text-right">Balance</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                <tr>
                                    <td><span class="font-mono text-xs font-medium text-primary">{{ $account->code }}</span></td>
                                    <td>
                                        <a href="{{ route('accounting.accounts.show', $account) }}" class="font-medium text-ink hover:text-primary">
                                            {{ $account->name }}
                                        </a>
                                    </td>
                                    <td><span class="text-ink-soft">{{ \App\Models\Account::typeLabel($account->type) }}</span></td>
                                    <td class="text-ink-soft">{{ $account->sub_type ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $account->parent?->code ?: '—' }}</td>
                                    <td class="text-right font-medium {{ $account->balance() < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-ink' }}">{{ money($account->balance()) }}</td>
                                    <td><x-accounting.status-badge :status="$account->is_active ? 'active' : 'inactive'" /></td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('accounting.chart_of_accounts.edit'))
                                            <div class="flex items-center justify-end gap-1">
                                                <a href="{{ route('accounting.accounts.show', $account) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                    <x-icon name="eye" class="size-4" />
                                                </a>
                                                <a href="{{ route('accounting.accounts.edit', $account) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                    <x-icon name="edit" class="size-4" />
                                                </a>
                                                @if (auth()->user()->can('accounting.chart_of_accounts.delete'))
                                                    <form method="POST" action="{{ route('accounting.accounts.destroy', $account) }}"
                                                        onsubmit="return confirm('Delete account {{ $account->code }} {{ $account->name }}?');">
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
