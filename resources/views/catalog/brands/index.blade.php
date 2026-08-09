<x-app-layout :pageTitle="'Brands'">
    <x-slot name="header">
        <x-page-header
            title="Brands"
            description="Brands organise your products and appear on orders, invoices and reports."
            icon="tag"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('catalog.brands.export'))
                    <x-export route="catalog.brands.export" />
                @endif
                @if (auth()->user()->can('catalog.brands.create'))
                    <x-button href="{{ route('catalog.brands.create') }}" icon="plus">New brand</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('catalog.brands.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Brand name…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-40">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'status']))
                    <x-button href="{{ route('catalog.brands.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($brands->isEmpty())
            <x-empty-state icon="tag" title="No brands found" description="Create a brand to start organising your products." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Brand</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($brands as $brand)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <x-icon name="tag" class="size-4" />
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-medium text-ink">{{ $brand->name }}</p>
                                            @if ($brand->slug)
                                                <p class="text-xs text-ink-faint">{{ $brand->slug }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="text-ink-soft">{{ $brand->products_count }} product{{ $brand->products_count === 1 ? '' : 's' }}</td>
                                <td>
                                    @if ($brand->is_active)
                                        <x-badge color="success" dot>Active</x-badge>
                                    @else
                                        <x-badge color="danger" dot>Inactive</x-badge>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if (auth()->user()->can('catalog.brands.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('catalog.brands.edit', $brand) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('catalog.brands.delete') && $brand->products_count === 0)
                                                <form method="POST" action="{{ route('catalog.brands.destroy', $brand) }}"
                                                    onsubmit="return confirm('Delete brand {{ $brand->name }}?');">
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

        @if ($brands->hasPages())
            <div class="px-5 py-4">
                {{ $brands->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
