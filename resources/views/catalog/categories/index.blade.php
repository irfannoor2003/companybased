<x-app-layout :pageTitle="'Categories'">
    <x-slot name="header">
        <x-page-header
            title="Categories"
            description="Organise products into a hierarchy that maps to your business."
            icon="document"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('catalog.categories.export'))
                    <x-export route="catalog.categories.export" />
                @endif
                @if (auth()->user()->can('catalog.categories.create'))
                    <x-button href="{{ route('catalog.categories.create') }}" icon="plus">New category</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('catalog.categories.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Category name…" leadingIcon="search"
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
                    <x-button href="{{ route('catalog.categories.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($categories->isEmpty())
            <x-empty-state icon="document" title="No categories found" description="Create a category to organise your products." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Parent</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($categories as $category)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <x-icon name="document" class="size-4" />
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-medium text-ink">{{ $category->name }}</p>
                                            <p class="text-xs text-ink-faint">{{ $category->slug }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-ink-soft">{{ $category->parent?->path() ?? '—' }}</td>
                                <td class="text-ink-soft">{{ $category->products_count }} product{{ $category->products_count === 1 ? '' : 's' }}</td>
                                <td>
                                    @if ($category->is_active)
                                        <x-badge color="success" dot>Active</x-badge>
                                    @else
                                        <x-badge color="danger" dot>Inactive</x-badge>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if (auth()->user()->can('catalog.categories.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('catalog.categories.edit', $category) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('catalog.categories.delete'))
                                                <form method="POST" action="{{ route('catalog.categories.destroy', $category) }}"
                                                    onsubmit="return confirm('Delete category {{ $category->name }}?');">
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

        @if ($categories->hasPages())
            <div class="px-5 py-4">
                {{ $categories->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
