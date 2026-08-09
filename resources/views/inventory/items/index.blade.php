<x-app-layout :pageTitle="'Inventory items'">
    <x-slot name="header">
        <x-page-header
            title="Inventory items"
            description="Tracked stock — quantities, reorder levels and warehouse levels."
            icon="inventory"
        >
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.items.export'))
                    <x-export route="inventory.items.export" />
                @endif
                @if (auth()->user()->can('inventory.items.create'))
                    <x-button href="{{ route('inventory.items.create') }}" icon="plus">Add item</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.items.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Name, SKU or barcode…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-48">
                <x-select name="warehouse" label="Warehouse" size="sm">
                    <option value="">All warehouses</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-40">
                <x-select name="stock_status" label="Stock level" size="sm">
                    <option value="">Any level</option>
                    <option value="low" @selected(request('stock_status') === 'low')>Low / reorder</option>
                    <option value="out" @selected(request('stock_status') === 'out')>Out of stock</option>
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
                @if (request()->hasAny(['search', 'warehouse', 'stock_status', 'status']))
                    <x-button href="{{ route('inventory.items.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($items->isEmpty())
            <x-empty-state icon="inventory" title="No items tracked" description="Add products to inventory to start tracking stock." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Unit</th>
                            <th class="text-right">On hand</th>
                            <th class="text-right">Reorder level</th>
                            <th class="text-right">Reorder qty</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            @php
                                $onHand = (float) $item->on_hand;
                                $low = $item->reorder_level > 0 && $onHand <= (float) $item->reorder_level;
                            @endphp
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <x-icon name="package" class="size-4" />
                                        </div>
                                        <div class="min-w-0">
                                            <a href="{{ route('inventory.items.show', $item) }}" class="font-medium text-ink hover:text-primary">
                                                {{ $item->product?->name }}
                                            </a>
                                            <p class="text-xs text-ink-faint">{{ $item->product?->sku ?: 'No SKU' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-ink-soft">{{ $item->product?->unit ?? '—' }}</td>
                                <td class="text-right">
                                    @if ($onHand <= 0)
                                        <span class="font-medium text-rose-500">0.000</span>
                                    @elseif ($low)
                                        <span class="font-medium text-amber-500">{{ number_format($onHand, 3) }}</span>
                                    @else
                                        <span class="font-medium text-ink">{{ number_format($onHand, 3) }}</span>
                                    @endif
                                </td>
                                <td class="text-right text-ink-soft">{{ $item->reorder_level }}</td>
                                <td class="text-right text-ink-soft">{{ $item->reorder_quantity }}</td>
                                <td>
                                    @if ($onHand <= 0)
                                        <x-inventory.status-badge status="out" />
                                    @elseif ($low)
                                        <x-inventory.status-badge status="low" />
                                    @else
                                        <x-inventory.status-badge status="ok" />
                                    @endif
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('inventory.items.show', $item) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                            <x-icon name="eye" class="size-4" />
                                        </a>
                                        @if (auth()->user()->can('inventory.items.adjust_stock'))
                                            <a href="{{ route('inventory.items.adjust', $item) }}" class="btn-ghost btn-icon btn-sm" title="Adjust stock">
                                                <x-icon name="zap" class="size-4" />
                                            </a>
                                        @endif
                                        @if (auth()->user()->can('inventory.items.edit'))
                                            <a href="{{ route('inventory.items.edit', $item) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($items->hasPages())
            <div class="px-5 py-4">
                {{ $items->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
