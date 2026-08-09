<x-app-layout :pageTitle="'Reconciliations'">
    <x-slot name="header">
        <x-page-header title="Reconciliations" description="Match your bank statement against the books." icon="check-circle">
            <x-slot name="actions">
                @if (auth()->user()->can('banking.reconciliations.export'))
                    <x-export route="banking.reconciliations.export" />
                @endif
                @if (auth()->user()->can('banking.reconciliations.create'))
                    <x-button href="{{ route('banking.reconciliations.create') }}" icon="plus">New reconciliation</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('banking.reconciliations.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[200px] flex-1">
                <x-input name="search" label="Search" placeholder="Reconciliation number…" leadingIcon="search"
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
            <div class="w-40">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\Reconciliation::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'account', 'status']))
                    <x-button href="{{ route('banking.reconciliations.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($reconciliations->isEmpty())
            <x-empty-state icon="check-circle" title="No reconciliations" description="Start a reconciliation to match your statement to the books." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Account</th>
                            <th>Statement date</th>
                            <th class="text-right">Book balance</th>
                            <th class="text-right">Statement</th>
                            <th class="text-right">Difference</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reconciliations as $reconciliation)
                            <tr>
                                <td><span class="font-mono text-xs font-medium text-primary">{{ $reconciliation->number }}</span></td>
                                <td class="text-ink">{{ $reconciliation->account?->name }}</td>
                                <td class="text-ink-soft">{{ $reconciliation->statement_date?->format('Y-m-d') }}</td>
                                <td class="text-right text-ink">{{ money($reconciliation->bookBalance(), $reconciliation->account?->currency) }}</td>
                                <td class="text-right text-ink">{{ money($reconciliation->statement_ending_balance, $reconciliation->account?->currency) }}</td>
                                <td class="text-right font-medium {{ $reconciliation->difference() != 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                    {{ money($reconciliation->difference(), $reconciliation->account?->currency) }}
                                </td>
                                <td><x-banking.status-badge :status="$reconciliation->status" /></td>
                                <td class="text-right">
                                    @if (auth()->user()->can('banking.reconciliations.edit'))
                                        <a href="{{ route('banking.reconciliations.edit', $reconciliation) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
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

        @if ($reconciliations->hasPages())
            <div class="px-5 py-4">
                {{ $reconciliations->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
