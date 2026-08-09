<x-app-layout :pageTitle="'Investment Reports'">
    <x-slot name="header">
        <x-page-header title="Reports" description="Portfolio summary, allocation by type and dividend income." icon="report">
            <x-slot name="actions">
                @if (auth()->user()->can('investments.reports.export'))
                    <x-export route="investments.reports.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-5">
        <x-stat-card label="Holdings" :value="$totals['count']" icon="investments" tone="primary" />
        <x-stat-card label="Invested" :value="money($totals['cost'])" icon="money" tone="info" />
        <x-stat-card label="Market value" :value="money($totals['value'])" icon="chart" tone="success" />
        <x-stat-card label="Return" :value="number_format($totals['return_pct'], 2).'%'" icon="report" :tone="$totals['gain_loss'] >= 0 ? 'success' : 'danger'" />
        <x-stat-card label="Dividends" :value="money($totals['dividends'])" icon="document" tone="warning" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card :padding="false">
            <div class="border-b border-line px-5 py-4">
                <h3 class="text-sm font-semibold text-ink">Allocation by type</h3>
            </div>
            @if ($byType->isEmpty())
                <x-empty-state icon="investments" title="No data" description="Add investments to see allocation." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Value</th>
                                <th class="text-right">Gain / Loss</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byType as $row)
                                <tr>
                                    <td class="font-medium text-ink">{{ ucfirst(str_replace('_', ' ', $row['type'])) }}</td>
                                    <td class="text-right text-ink-soft">{{ $row['count'] }}</td>
                                    <td class="text-right text-ink-soft">{{ money($row['cost']) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($row['value']) }}</td>
                                    <td class="text-right {{ $row['gain_loss'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $row['gain_loss'] >= 0 ? '+' : '−' }}{{ money(abs($row['gain_loss'])) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <x-card :padding="false">
            <div class="border-b border-line px-5 py-4">
                <h3 class="text-sm font-semibold text-ink">Dividends by year</h3>
            </div>
            @if ($byYear->isEmpty())
                <x-empty-state icon="document" title="No dividends yet" description="Dividend income will appear here by year." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th class="text-right">Income</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byYear as $row)
                                <tr>
                                    <td class="font-medium text-ink">{{ $row->year }}</td>
                                    <td class="text-right font-medium text-success">{{ money($row->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>