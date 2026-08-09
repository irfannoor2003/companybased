<x-app-layout :pageTitle="'Investment Returns'">
    <x-slot name="header">
        <x-page-header title="Returns" description="Gain and loss across the portfolio versus cost basis." icon="chart">
            <x-slot name="actions">
                @if (auth()->user()->can('investments.returns.export'))
                    <x-export route="investments.returns.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-stat-card label="Invested" :value="money($totals['cost'])" icon="money" tone="info" />
        <x-stat-card label="Market value" :value="money($totals['value'])" icon="chart" tone="primary" />
        <x-stat-card label="Gain / Loss" :value="money($totals['gain_loss'])" icon="chart" :tone="$totals['gain_loss'] >= 0 ? 'success' : 'danger'" />
        <x-stat-card label="Return" :value="number_format($totals['return_pct'], 2).'%'" icon="report" :tone="$totals['gain_loss'] >= 0 ? 'success' : 'danger'" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('investments.returns.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Code, name…" leadingIcon="search" value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-44">
                    <x-select name="type" label="Type" size="sm">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'type']))
                        <x-button href="{{ route('investments.returns.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($investments->isEmpty())
                <x-empty-state icon="chart" title="No holdings" description="Add investments to calculate returns." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Market value</th>
                                <th class="text-right">Gain / Loss</th>
                                <th class="text-right">Return</th>
                                <th class="text-right">Dividends</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($investments as $investment)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $investment->code }}</td>
                                    <td class="text-ink-soft">{{ $investment->name }}</td>
                                    <td class="text-right text-ink-soft">{{ money($investment->total_cost, $investment->currency) }}</td>
                                    <td class="text-right text-ink-soft">{{ money($investment->marketValue(), $investment->currency) }}</td>
                                    <td class="text-right font-medium {{ $investment->gainLoss() >= 0 ? 'text-success' : 'text-danger' }}">{{ $investment->gainLoss() >= 0 ? '+' : '−' }}{{ money(abs($investment->gainLoss()), $investment->currency) }}</td>
                                    <td class="text-right font-semibold {{ $investment->gainLoss() >= 0 ? 'text-success' : 'text-danger' }}">{{ number_format($investment->returnPct(), 2) }}%</td>
                                    <td class="text-right text-ink-soft">{{ money($investment->totalDividends(), $investment->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>