<x-app-layout :pageTitle="'Incoming Shipments'">
    <x-slot name="header">
        <x-page-header title="Incoming Shipments" description="Stock due to arrive from suppliers and the approval flow into your warehouse." icon="truck">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.incoming_shipments.export'))
                    <x-export route="inventory.incoming_shipments.export" />
                @endif
                @if (auth()->user()->can('inventory.incoming_shipments.create'))
                    <x-button href="{{ route('inventory.incoming_shipments.create') }}" icon="plus">New shipment</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.incoming_shipments.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Shipment number…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-40">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\InventoryIncomingShipment::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="w-48">
                <x-select name="warehouse" label="Warehouse" size="sm">
                    <option value="">Any warehouse</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(request('warehouse') == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </x-select>
            </div>
            <label class="flex items-center gap-2 text-sm text-ink-soft">
                <input type="checkbox" name="overdue" value="1" @checked(request('overdue')) class="size-4 rounded border-line text-primary focus:ring-primary">
                Overdue (not approved)
            </label>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'status', 'warehouse', 'overdue']))
                    <x-button href="{{ route('inventory.incoming_shipments.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($shipments->isEmpty())
            <x-empty-state icon="truck" title="No incoming shipments" description="Create a shipment to track stock expected from suppliers." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Supplier</th>
                            <th>Warehouse</th>
                            <th>Expected</th>
                            <th>Lines</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shipments as $shipment)
                            <tr>
                                <td class="font-mono text-xs font-medium text-primary">{{ $shipment->number }}</td>
                                <td class="text-ink-soft">{{ $shipment->supplier?->name ?? '—' }}</td>
                                <td class="text-ink-soft">{{ $shipment->warehouse?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap text-ink-soft">{{ $shipment->expected_arrival_at?->format('Y-m-d') ?: '—' }}</td>
                                <td class="text-ink-soft">{{ $shipment->items_count ?? $shipment->items()->count() }}</td>
                                <td><x-inventory.status-badge :status="$shipment->status" /></td>
                                <td class="text-right">
                                    @if (auth()->user()->can('inventory.incoming_shipments.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('inventory.incoming_shipments.show', $shipment) }}" class="btn-ghost btn-icon btn-sm" title="View">
                                                <x-icon name="eye" class="size-4" />
                                            </a>
                                            @if (! $shipment->isLocked())
                                                <a href="{{ route('inventory.incoming_shipments.edit', $shipment) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                    <x-icon name="edit" class="size-4" />
                                                </a>
                                            @endif
                                            @if (auth()->user()->can('inventory.incoming_shipments.delete') && ! $shipment->isLocked())
                                                <form method="POST" action="{{ route('inventory.incoming_shipments.destroy', $shipment) }}"
                                                    onsubmit="return confirm('Delete shipment {{ $shipment->number }}?');">
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

        @if ($shipments->hasPages())
            <div class="px-5 py-4">
                {{ $shipments->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
