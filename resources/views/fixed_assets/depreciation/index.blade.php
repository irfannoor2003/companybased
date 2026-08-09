<x-app-layout :pageTitle="'Depreciation'">
    <x-slot name="header">
        <x-page-header title="Depreciation" description="Run and review depreciation charges for each period." icon="clock">
            <x-slot name="actions">
                @if (auth()->user()->can('fixed_assets.depreciation.export'))
                    <x-export route="fixed_assets.depreciation.export" />
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('fixed_assets._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Period" :value="$period" icon="clock" tone="primary" />
        <x-stat-card label="Charges" :value="$records->count()" icon="document" tone="info" />
        <x-stat-card label="Period total" :value="money($periodTotal)" icon="money" tone="warning" />
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card :padding="false">
                <div class="flex flex-wrap items-end justify-between gap-3 border-b border-line px-5 py-4">
                    <form method="GET" action="{{ route('fixed_assets.depreciation.index') }}" class="flex flex-wrap items-end gap-3">
                        <div class="w-48">
                            <x-select name="period" label="Period" size="sm">
                                <option value="{{ now()->format('Y-m') }}">{{ now()->format('Y-m') }}</option>
                                @foreach ($periods as $p)
                                    <option value="{{ $p }}" @selected($period === $p)>{{ $p }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <x-button type="submit" size="sm" icon="filter">View</x-button>
                    </form>
                    @if (auth()->user()->can('fixed_assets.depreciation.run'))
                        <form method="POST" action="{{ route('fixed_assets.depreciation.run') }}" class="flex items-end gap-3">
                            @csrf
                            <input type="hidden" name="period" value="{{ $period }}">
                            <x-button type="submit" size="sm" variant="secondary" icon="zap">Run depreciation</x-button>
                        </form>
                    @endif
                </div>

                <div class="max-h-[560px] overflow-auto">
                    @if ($records->isEmpty())
                        <x-empty-state icon="clock" title="No depreciation records" description="Select the assets below and run depreciation for this period." />
                    @else
                        <div class="table-wrap !border-0 !rounded-none">
                            <table class="table-base">
                                <thead>
                                    <tr>
                                        <th>Asset</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($records as $record)
                                        <tr>
                                            <td>
                                                <span class="font-mono text-xs text-ink-faint">{{ $record->asset?->asset_code }}</span>
                                                <span class="block text-ink-soft">{{ $record->asset?->name }}</span>
                                            </td>
                                            <td class="text-right font-medium text-ink">{{ money($record->amount) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-line">
                                        <td class="text-right font-semibold text-ink">Total</td>
                                        <td class="text-right font-semibold text-ink">{{ money($periodTotal) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            </x-card>
        </div>

        <div>
            <x-card :padding="false">
                <div class="border-b border-line px-5 py-4">
                    <h3 class="text-sm font-semibold text-ink">Eligible assets</h3>
                    <p class="text-xs text-ink-faint">Assets that can be depreciated for {{ $period }}.</p>
                </div>
                <ul class="divide-y divide-line">
                    @foreach ($assets as $asset)
                        @php
                            $canRun = $asset->status !== 'disposed' && ! $asset->isFullyDepreciated() && ! $records->contains('fixed_asset_id', $asset->id);
                        @endphp
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <span class="block truncate text-sm font-medium text-ink">{{ $asset->name }}</span>
                                <span class="block text-xs text-ink-faint">{{ $asset->asset_code }} · monthly {{ money($asset->monthlyDepreciation()) }}</span>
                            </div>
                            @if ($canRun)
                                <form method="POST" action="{{ route('fixed_assets.depreciation.run') }}">
                                    @csrf
                                    <input type="hidden" name="period" value="{{ $period }}">
                                    <input type="hidden" name="assets[]" value="{{ $asset->id }}">
                                    <x-button type="submit" size="sm" variant="ghost" icon="zap">Run</x-button>
                                </form>
                            @else
                                <x-badge color="{{ $asset->isFullyDepreciated() ? 'warning' : ($asset->status === 'disposed' ? 'danger' : 'success') }}">{{ $asset->isFullyDepreciated() ? 'Fully depreciated' : ($asset->status === 'disposed' ? 'Disposed' : 'Done') }}</x-badge>
                            @endif
                        </li>
                    @endforeach
                    @if ($assets->isEmpty())
                        <li class="px-5 py-6 text-center text-sm text-ink-faint">No assets registered.</li>
                    @endif
                </ul>
            </x-card>
        </div>
    </div>
</x-app-layout>