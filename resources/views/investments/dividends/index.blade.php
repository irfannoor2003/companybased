<x-app-layout :pageTitle="'Investment Dividends'">
    <x-slot name="header">
        <x-page-header title="Dividends" description="Dividend and interest income received from holdings." icon="document">
            <x-slot name="actions">
                @if (auth()->user()->can('investments.dividends.export'))
                    <x-export route="investments.dividends.export" />
                @endif
                @if (auth()->user()->can('investments.dividends.create'))
                    <x-button href="{{ route('investments.dividends.create') }}" icon="plus">Record dividend</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Total dividends received" :value="money($total)" icon="document" tone="success" />
        <x-stat-card label="Payments" :value="$dividends->total()" icon="money" tone="primary" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('investments.dividends.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
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
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'investment_id', 'from', 'to']))
                        <x-button href="{{ route('investments.dividends.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($dividends->isEmpty())
                <x-empty-state icon="document" title="No dividends" description="Record dividend income to track it." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Investment</th>
                                <th class="text-right">Amount</th>
                                <th>Currency</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dividends as $dividend)
                                <tr>
                                    <td class="text-ink-soft">{{ $dividend->dividend_date?->format('Y-m-d') }}</td>
                                    <td>
                                        <span class="font-mono text-xs text-ink-faint">{{ $dividend->investment?->code }}</span>
                                        <span class="block text-ink-soft">{{ $dividend->investment?->name }}</span>
                                    </td>
                                    <td class="text-right font-medium text-success">{{ money($dividend->amount, $dividend->currency) }}</td>
                                    <td class="text-ink-faint">{{ $dividend->currency ?: '—' }}</td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('investments.dividends.edit'))
                                            <a href="{{ route('investments.dividends.edit', $dividend) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('investments.dividends.delete'))
                                            <form method="POST" action="{{ route('investments.dividends.destroy', $dividend) }}" class="inline" onsubmit="return confirm('Delete this dividend?')">
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

            @if ($dividends->hasPages())
                <div class="px-5 py-4">
                    {{ $dividends->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>