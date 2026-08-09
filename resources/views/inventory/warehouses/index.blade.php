<x-app-layout :pageTitle="'Warehouses'">
    <x-slot name="header">
        <x-page-header title="Warehouses" description="Locations where stock is held." icon="building">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.warehouses.export'))
                    <x-export route="inventory.warehouses.export" />
                @endif
                @if (auth()->user()->can('inventory.warehouses.create'))
                    <x-button href="{{ route('inventory.warehouses.create') }}" icon="plus">New warehouse</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.warehouses.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Name or code…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
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
                @if (request()->hasAny(['search', 'status']))
                    <x-button href="{{ route('inventory.warehouses.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($warehouses->isEmpty())
            <x-empty-state icon="building" title="No warehouses" description="Create a warehouse to start tracking stock locations." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th class="text-right">Stock lines</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($warehouses as $warehouse)
                            <tr>
                                <td>
                                    <span class="inline-flex items-center rounded-md bg-primary/10 px-2 py-0.5 font-mono text-xs font-medium text-primary">
                                        {{ $warehouse->code }}
                                    </span>
                                </td>
                                <td class="font-medium text-ink">{{ $warehouse->name }}</td>
                                <td class="text-ink-soft">{{ $warehouse->address ?? '—' }}</td>
                                <td class="text-right text-ink-soft">{{ $warehouse->stock_count }}</td>
                                <td>
                                    <x-inventory.status-badge :status="$warehouse->is_active ? 'active' : 'inactive'" />
                                </td>
                                <td class="text-right">
                                    @if (auth()->user()->can('inventory.warehouses.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('inventory.warehouses.edit', $warehouse) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('inventory.warehouses.delete'))
                                                <form method="POST" action="{{ route('inventory.warehouses.destroy', $warehouse) }}"
                                                    onsubmit="return confirm('Delete warehouse {{ $warehouse->name }}?');">
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

        @if ($warehouses->hasPages())
            <div class="px-5 py-4">
                {{ $warehouses->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
