<x-app-layout :pageTitle="'Asset Disposals'">
    <x-slot name="header">
        <x-page-header title="Disposals" description="Record the sale or retirement of fixed assets." icon="arrow-right">
            <x-slot name="actions">
                @if (auth()->user()->can('fixed_assets.disposals.export'))
                    <x-export route="fixed_assets.disposals.export" />
                @endif
                @if (auth()->user()->can('fixed_assets.disposals.create'))
                    <x-button href="{{ route('fixed_assets.disposals.create') }}" icon="plus">Record disposal</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('fixed_assets._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Disposals" :value="$disposals->total()" icon="arrow-right" tone="primary" />
        <x-stat-card label="Total proceeds" :value="money($totalProceeds)" icon="money" tone="success" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('fixed_assets.disposals.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Asset code or name…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-40">
                    <x-select name="method" label="Method" size="sm">
                        <option value="">Any method</option>
                        @foreach (\App\Models\FixedAssetDisposal::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(request('method') === $method)>{{ ucfirst($method) }}</option>
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
                    @if (request()->hasAny(['search', 'method', 'from', 'to']))
                        <x-button href="{{ route('fixed_assets.disposals.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($disposals->isEmpty())
                <x-empty-state icon="arrow-right" title="No disposals" description="Record a disposal to retire an asset." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Asset</th>
                                <th>Method</th>
                                <th class="text-right">Book value</th>
                                <th class="text-right">Proceeds</th>
                                <th class="text-right">Gain / Loss</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($disposals as $disposal)
                                @php $gainLoss = round((float) $disposal->proceeds - (float) $disposal->book_value, 2); @endphp
                                <tr>
                                    <td class="text-ink-soft">{{ $disposal->disposal_date?->format('Y-m-d') }}</td>
                                    <td>
                                        <span class="font-mono text-xs text-ink-faint">{{ $disposal->asset?->asset_code }}</span>
                                        <span class="block text-ink-soft">{{ $disposal->asset?->name }}</span>
                                    </td>
                                    <td><x-badge color="neutral">{{ ucfirst($disposal->method) }}</x-badge></td>
                                    <td class="text-right text-ink-soft">{{ money($disposal->book_value) }}</td>
                                    <td class="text-right font-medium text-success">{{ money($disposal->proceeds) }}</td>
                                    <td class="text-right font-medium {{ $gainLoss >= 0 ? 'text-success' : 'text-danger' }}">{{ $gainLoss >= 0 ? '+' : '−' }}{{ money(abs($gainLoss)) }}</td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('fixed_assets.disposals.edit'))
                                            <a href="{{ route('fixed_assets.disposals.edit', $disposal) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('fixed_assets.disposals.delete'))
                                            <form method="POST" action="{{ route('fixed_assets.disposals.destroy', $disposal) }}" class="inline" onsubmit="return confirm('Delete this disposal?')">
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

            @if ($disposals->hasPages())
                <div class="px-5 py-4">
                    {{ $disposals->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>