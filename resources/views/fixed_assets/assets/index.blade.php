<x-app-layout :pageTitle="'Fixed Assets'">
    <x-slot name="header">
        <x-page-header title="Assets" description="Register of company fixed assets and their book values." icon="assets">
            <x-slot name="actions">
                @if (auth()->user()->can('fixed_assets.assets.export'))
                    <x-export route="fixed_assets.assets.export" />
                @endif
                @if (auth()->user()->can('fixed_assets.assets.create'))
                    <x-button href="{{ route('fixed_assets.assets.create') }}" icon="plus">Add asset</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('fixed_assets._tabs')

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-stat-card label="Assets" :value="$stats['count']" icon="assets" tone="primary" />
        <x-stat-card label="Total cost" :value="money($stats['cost'])" icon="money" tone="info" />
        <x-stat-card label="Accumulated depreciation" :value="money($stats['depreciation'])" icon="clock" tone="warning" />
        <x-stat-card label="Net book value" :value="money($stats['book_value'])" icon="chart" tone="success" />
    </div>

    <div class="mt-6">
        <x-card :padding="false">
            <form method="GET" action="{{ route('fixed_assets.assets.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
                <div class="min-w-[220px] flex-1">
                    <x-input name="search" label="Search" placeholder="Code, name, serial…" leadingIcon="search"
                        value="{{ request('search') }}" size="sm" />
                </div>
                <div class="w-48">
                    <x-select name="category" label="Category" size="sm">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-select name="status" label="Status" size="sm">
                        <option value="">Any status</option>
                        @foreach (\App\Models\FixedAsset::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                    @if (request()->hasAny(['search', 'category', 'status']))
                        <x-button href="{{ route('fixed_assets.assets.index') }}" variant="ghost" size="sm">Clear</x-button>
                    @endif
                </div>
            </form>

            @if ($assets->isEmpty())
                <x-empty-state icon="assets" title="No assets" description="Add a fixed asset to build the register." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Purchased</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Book value</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($assets as $asset)
                                <tr>
                                    <td class="font-mono font-medium text-ink">{{ $asset->asset_code }}</td>
                                    <td class="text-ink-soft">
                                        {{ $asset->name }}
                                        @if ($asset->serial_number)
                                            <span class="block text-xs text-ink-faint">{{ $asset->serial_number }}</span>
                                        @endif
                                    </td>
                                    <td class="text-ink-faint">{{ $asset->category ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $asset->purchase_date?->format('Y-m-d') ?: '—' }}</td>
                                    <td class="text-right text-ink-soft">{{ money($asset->purchase_cost) }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($asset->bookValue()) }}</td>
                                    <td>
                                        <x-badge :color="match ($asset->status) {
                                            'in_use' => 'success',
                                            'stored' => 'info',
                                            'disposed' => 'danger',
                                            default => 'neutral',
                                        }">{{ ucfirst(str_replace('_', ' ', $asset->status)) }}</x-badge>
                                    </td>
                                    <td class="text-right">
                                        @if (auth()->user()->can('fixed_assets.assets.edit'))
                                            <a href="{{ route('fixed_assets.assets.edit', $asset) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('fixed_assets.assets.delete'))
                                            <form method="POST" action="{{ route('fixed_assets.assets.destroy', $asset) }}" class="inline" onsubmit="return confirm('Delete asset {{ $asset->asset_code }}?')">
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

            @if ($assets->hasPages())
                <div class="px-5 py-4">
                    {{ $assets->links() }}
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>