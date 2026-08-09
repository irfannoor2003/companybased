<x-app-layout :pageTitle="'Capital Equity'">
    <x-slot name="header">
        <x-page-header title="Equity" description="Owners' equity summary — total capital minus drawings." icon="chart">
            <x-slot name="actions">
                @if (auth()->user()->can('capital_accounts.equity.export'))
                    <x-export route="capital.equity.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('capital._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Total contributions" :value="money($totals['contributions'])" icon="money" tone="success" />
        <x-stat-card label="Total drawings" :value="money($totals['drawings'])" icon="arrow-right" tone="danger" />
        <x-stat-card label="Current equity" :value="money($totals['equity'])" icon="chart" tone="primary" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('capital.equity.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="w-40">
                    <x-input name="from" label="From" type="date" value="{{ request('from') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-input name="to" label="To" type="date" value="{{ request('to') }}" size="sm" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['from', 'to']))
                        <x-button href="{{ route('capital.equity.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($parties->isEmpty())
                <x-empty-state icon="chart" title="No capital activity" description="Record contributions or drawings to see equity." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th class="text-right">Contributions</th>
                                <th class="text-right">Drawings</th>
                                <th class="text-right">Equity</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($parties as $party)
                                <tr>
                                    <td class="font-medium text-ink">{{ $party['owner'] }}</td>
                                    <td class="text-right text-success">{{ money($party['contributions']) }}</td>
                                    <td class="text-right text-danger">{{ money($party['drawings']) }}</td>
                                    <td class="text-right font-semibold {{ $party['equity'] >= 0 ? 'text-success' : 'text-danger' }}">{{ money($party['equity']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>