<x-app-layout :pageTitle="'Transfers'">
    <x-slot name="header">
        <x-page-header title="Transfers" description="Move stock between warehouses." icon="arrow-right">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.transfers.export'))
                    <x-export route="inventory.transfers.export" />
                @endif
                @if (auth()->user()->can('inventory.transfers.create'))
                    <x-button href="{{ route('inventory.transfers.create') }}" icon="plus">New transfer</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.transfers.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Transfer number…" leadingIcon="search"
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
                    @foreach (\App\Models\InventoryTransfer::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'warehouse', 'status']))
                    <x-button href="{{ route('inventory.transfers.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($transfers->isEmpty())
            <x-empty-state icon="arrow-right" title="No transfers" description="Create a transfer to move stock between warehouses." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Route</th>
                            <th>Date</th>
                            <th class="text-right">Items</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transfers as $transfer)
                            <tr>
                                <td class="font-mono text-xs font-medium text-primary">{{ $transfer->number }}</td>
                                <td class="text-ink-soft">
                                    {{ $transfer->fromWarehouse?->name }}
                                    <x-icon name="arrow-right" class="mx-1 inline size-3.5 text-ink-faint" />
                                    {{ $transfer->toWarehouse?->name }}
                                </td>
                                <td class="whitespace-nowrap text-ink-soft">{{ $transfer->transfer_date?->format('Y-m-d') }}</td>
                                <td class="text-right text-ink-soft">{{ $transfer->items_count ?? $transfer->items()->count() }}</td>
                                <td><x-inventory.status-badge :status="$transfer->status" /></td>
                                <td class="text-right">
                                    @if (auth()->user()->can('inventory.transfers.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('inventory.transfers.edit', $transfer) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('inventory.transfers.delete'))
                                                <form method="POST" action="{{ route('inventory.transfers.destroy', $transfer) }}"
                                                    onsubmit="return confirm('Delete transfer {{ $transfer->number }}?');">
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

        @if ($transfers->hasPages())
            <div class="px-5 py-4">
                {{ $transfers->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
