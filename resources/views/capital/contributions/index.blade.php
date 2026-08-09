<x-app-layout :pageTitle="'Capital Contributions'">
    <x-slot name="header">
        <x-page-header title="Contributions" description="Owner and investor capital injections into the business." icon="money">
            <x-slot name="actions">
                @if (auth()->user()->can('capital_accounts.contributions.export'))
                    <x-export route="capital.contributions.export" />
                @endif
                @if (auth()->user()->can('capital_accounts.contributions.create'))
                    <x-button href="{{ route('capital.contributions.create') }}" icon="plus">Record contribution</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('capital._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Total contributions" :value="money($total)" icon="money" tone="success" />
        <x-stat-card label="Transactions" :value="$contributions->total()" icon="document" tone="primary" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('capital.contributions.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Reference, contributor, notes…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-48">
                    <x-select name="contributor" label="Contributor" size="sm">
                        <option value="">All contributors</option>
                        @foreach ($contributors as $contributor)
                            <option value="{{ $contributor }}" @selected(request('contributor') === $contributor)>{{ $contributor }}</option>
                        @endforeach
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
                    @if (request()->hasAny(['search', 'contributor', 'from', 'to']))
                        <x-button href="{{ route('capital.contributions.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($contributions->isEmpty())
                <x-empty-state icon="money" title="No contributions" description="Record an owner contribution to get started." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Contributor</th>
                                <th>Method</th>
                                <th class="text-right">Amount</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($contributions as $contribution)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $contribution->reference }}</td>
                                    <td class="text-ink-soft">{{ $contribution->contribution_date?->format('Y-m-d') }}</td>
                                    <td class="text-ink-soft">{{ $contribution->contributor }}</td>
                                    <td class="text-ink-faint">{{ $contribution->method ? ucfirst(str_replace('_', ' ', $contribution->method)) : '—' }}</td>
                                    <td class="text-right font-medium text-success">{{ money($contribution->amount, $contribution->currency) }}</td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('capital_accounts.contributions.edit'))
                                            <a href="{{ route('capital.contributions.edit', $contribution) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('capital_accounts.contributions.delete'))
                                            <form method="POST" action="{{ route('capital.contributions.destroy', $contribution) }}" class="inline" onsubmit="return confirm('Delete contribution {{ $contribution->reference }}?')">
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

            @if ($contributions->hasPages())
                <div class="px-5 py-4">
                    {{ $contributions->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
