<x-app-layout :pageTitle="'Products'">
    <x-slot name="header">
        <x-page-header
            title="Products"
            description="The full product catalogue — pricing, brands, categories and units."
            icon="package"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('catalog.products.export'))
                    <x-export route="catalog.products.export" />
                @endif
                @if (auth()->user()->can('catalog.products.create'))
                    <x-button href="{{ route('catalog.products.create') }}" icon="plus">New product</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('catalog.products.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Name, SKU or barcode…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-48">
                <x-select name="brand" label="Brand" size="sm">
                    <option value="">All brands</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(request('brand') == $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-48">
                <x-select name="category" label="Category" size="sm">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->path() }}</option>
                    @endforeach
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
                @if (request()->hasAny(['search', 'brand', 'category', 'status']))
                    <x-button href="{{ route('catalog.products.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($products->isEmpty())
            <x-empty-state icon="package" title="No products found" description="Create a product to start building your catalogue." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Brand</th>
                            <th>Category</th>
                            <th class="text-right">Cost</th>
                            <th class="text-right">Retail</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <x-icon name="package" class="size-4" />
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-medium text-ink">{{ $product->name }}</p>
                                            <p class="text-xs text-ink-faint">
                                                {{ $product->sku ?: 'No SKU' }}@if ($product->barcode) · {{ $product->barcode }}@endif
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-ink-soft">{{ $product->brand?->name ?? '—' }}</td>
                                <td class="text-ink-soft">{{ $product->category?->path() ?? '—' }}</td>
                                <td class="text-right text-ink-soft">{{ money($product->cost_price) }}</td>
                                <td class="text-right font-medium text-ink">{{ money($product->retail_price) }}</td>
                                <td>
                                    @if ($product->is_active)
                                        <x-badge color="success" dot>Active</x-badge>
                                    @else
                                        <x-badge color="danger" dot>Inactive</x-badge>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if (auth()->user()->can('catalog.products.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('catalog.products.edit', $product) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('catalog.products.delete'))
                                                <form method="POST" action="{{ route('catalog.products.destroy', $product) }}"
                                                    onsubmit="return confirm('Delete product {{ $product->name }}?');">
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

        @if ($products->hasPages())
            <div class="px-5 py-4">
                {{ $products->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
