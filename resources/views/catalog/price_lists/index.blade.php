<x-app-layout :pageTitle="'Price lists'">
    <x-slot name="header">
        <x-page-header
            title="Price lists"
            description="Define customer-specific pricing by overriding catalogue prices per list."
            icon="money"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('catalog.price_lists.export'))
                    <x-export route="catalog.price_lists.export" />
                @endif
                @if (auth()->user()->can('catalog.price_lists.create'))
                    <x-button href="{{ route('catalog.price_lists.create') }}" icon="plus">New price list</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('catalog.price_lists.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Price list name…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-40">
                <x-select name="type" label="Type" size="sm">
                    <option value="">All types</option>
                    <option value="retail" @selected(request('type') === 'retail')>Retail</option>
                    <option value="wholesale" @selected(request('type') === 'wholesale')>Wholesale</option>
                    <option value="custom" @selected(request('type') === 'custom')>Custom</option>
                </x-select>
            </div>
            <div class="w-36">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'type', 'status']))
                    <x-button href="{{ route('catalog.price_lists.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($priceLists->isEmpty())
            <x-empty-state icon="money" title="No price lists found" description="Create a price list to give specific customers their own pricing." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Price list</th>
                            <th>Type</th>
                            <th>Markup</th>
                            <th>Items</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($priceLists as $priceList)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <x-icon name="money" class="size-4" />
                                        </div>
                                        <div class="min-w-0">
                                            <p class="flex items-center gap-2 font-medium text-ink">
                                                {{ $priceList->name }}
                                                @if ($priceList->is_default)
                                                    <x-badge color="primary">Default</x-badge>
                                                @endif
                                            </p>
                                            @if ($priceList->currency)
                                                <p class="text-xs text-ink-faint">{{ $priceList->currency }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <x-badge color="neutral">{{ ucfirst($priceList->type) }}</x-badge>
                                </td>
                                <td class="text-ink-soft">{{ $priceList->markup_percent > 0 ? $priceList->markup_percent.'%' : '—' }}</td>
                                <td class="text-ink-soft">{{ $priceList->items_count }} product{{ $priceList->items_count === 1 ? '' : 's' }}</td>
                                <td>
                                    @if ($priceList->is_active)
                                        <x-badge color="success" dot>Active</x-badge>
                                    @else
                                        <x-badge color="danger" dot>Inactive</x-badge>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if (auth()->user()->can('catalog.price_lists.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('catalog.price_lists.edit', $priceList) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('catalog.price_lists.delete'))
                                                <form method="POST" action="{{ route('catalog.price_lists.destroy', $priceList) }}"
                                                    onsubmit="return confirm('Delete price list {{ $priceList->name }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Delete">
                                                        <x-icon name="trash" class="size-4" />
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($priceLists->hasPages())
            <div class="px-5 py-4">
                {{ $priceLists->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
