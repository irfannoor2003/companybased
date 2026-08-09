<x-app-layout :pageTitle="'Fixed Asset Reports'">
    <x-slot name="header">
        <x-page-header title="Reports" description="Asset register, category summary and depreciation by period." icon="report">
            <x-slot name="actions">
                @if (auth()->user()->can('fixed_assets.reports.export'))
                    <x-export route="fixed_assets.reports.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('fixed_assets._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-5">
        <x-stat-card label="Assets" :value="$totals['count']" icon="assets" tone="primary" />
        <x-stat-card label="Total cost" :value="money($totals['cost'])" icon="money" tone="info" />
        <x-stat-card label="Depreciation" :value="money($totals['depreciation'])" icon="clock" tone="warning" />
        <x-stat-card label="Net book value" :value="money($totals['book_value'])" icon="chart" tone="success" />
        <x-stat-card label="Disposals" :value="money($totals['disposal_proceeds'])" icon="arrow-right" tone="danger" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card :padding="false">
            <div class="border-b border-line px-5 py-4">
                <h3 class="text-sm font-semibold text-ink">By category</h3>
            </div>
            @if ($byCategory->isEmpty())
                <x-empty-state icon="assets" title="No data" description="Register assets to see the category summary." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Depreciation</th>
                                <th class="text-right">Book value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byCategory as $row)
                                <tr>
                                    <td class="font-medium text-ink">{{ $row['category'] }}</td>
                                    <td class="text-right text-ink-soft">{{ $row['count'] }}</td>
                                    <td class="text-right text-ink-soft">{{ money($row['cost']) }}</td>
                                    <td class="text-right text-warning">{{ money($row['depreciation']) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($row['book_value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <x-card :padding="false">
            <div class="border-b border-line px-5 py-4">
                <h3 class="text-sm font-semibold text-ink">Depreciation by period</h3>
            </div>
            @if ($byPeriod->isEmpty())
                <x-empty-state icon="clock" title="No depreciation yet" description="Run depreciation to populate the schedule." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th class="text-right">Charge</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byPeriod as $row)
                                <tr>
                                    <td class="font-mono text-ink">{{ $row->period }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($row->total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>