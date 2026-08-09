<x-app-layout :pageTitle="'Production Orders'">
    <x-slot name="header">
        <x-page-header title="Production Orders" description="Plan and track manufacturing runs." icon="zap">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.production_orders.export'))
                    <x-export route="inventory.production_orders.export" />
                @endif
                @if (auth()->user()->can('inventory.production_orders.create'))
                    <x-button href="{{ route('inventory.production_orders.create') }}" icon="plus">New order</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.production_orders.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Order number…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-48">
                <x-select name="warehouse" label="Warehouse" size="sm">
                    <option value="">Any warehouse</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-40">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\InventoryProductionOrder::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'warehouse', 'status']))
                    <x-button href="{{ route('inventory.production_orders.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($orders->isEmpty())
            <x-empty-state icon="zap" title="No production orders" description="Create a production order to manufacture finished items." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Item</th>
                            <th class="text-right">Qty</th>
                            <th>Warehouse</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td class="font-mono text-xs font-medium text-primary">{{ $order->number }}</td>
                                <td class="font-medium text-ink">{{ $order->item?->product?->name }}</td>
                                <td class="text-right text-ink-soft">{{ $order->quantity }}</td>
                                <td class="text-ink-soft">{{ $order->warehouse?->name }}</td>
                                <td class="whitespace-nowrap text-ink-soft">
                                    @if ($order->scheduled_start_date)
                                        {{ $order->scheduled_start_date->format('Y-m-d') }}
                                        @if ($order->scheduled_end_date)
                                            → {{ $order->scheduled_end_date->format('Y-m-d') }}
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td><x-inventory.status-badge :status="$order->status" /></td>
                                <td class="text-right">
                                    @if (auth()->user()->can('inventory.production_orders.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('inventory.production_orders.edit', $order) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('inventory.production_orders.delete'))
                                                <form method="POST" action="{{ route('inventory.production_orders.destroy', $order) }}"
                                                    onsubmit="return confirm('Delete production order {{ $order->number }}?');">
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

        @if ($orders->hasPages())
            <div class="px-5 py-4">
                {{ $orders->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
