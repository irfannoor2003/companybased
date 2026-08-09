<x-app-layout :pageTitle="'Write-Offs'">
    <x-slot name="header">
        <x-page-header title="Write-Offs" description="Record damaged, expired or lost stock." icon="trash">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.write_offs.export'))
                    <x-export route="inventory.write_offs.export" />
                @endif
                @if (auth()->user()->can('inventory.write_offs.create'))
                    <x-button href="{{ route('inventory.write_offs.create') }}" icon="plus">New write-off</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.write_offs.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="Write-off number…" leadingIcon="search"
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
                    @foreach (\App\Models\InventoryWriteOff::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'warehouse', 'status']))
                    <x-button href="{{ route('inventory.write_offs.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($writeOffs->isEmpty())
            <x-empty-state icon="trash" title="No write-offs" description="Record write-offs to remove damaged or expired stock." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Warehouse</th>
                            <th>Date</th>
                            <th>Reason</th>
                            <th class="text-right">Items</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($writeOffs as $writeOff)
                            <tr>
                                <td class="font-mono text-xs font-medium text-primary">{{ $writeOff->number }}</td>
                                <td class="text-ink-soft">{{ $writeOff->warehouse?->name }}</td>
                                <td class="whitespace-nowrap text-ink-soft">{{ $writeOff->write_off_date?->format('Y-m-d') }}</td>
                                <td class="max-w-[200px] truncate text-ink-soft">{{ $writeOff->reason ?? '—' }}</td>
                                <td class="text-right text-ink-soft">{{ $writeOff->items_count ?? $writeOff->items()->count() }}</td>
                                <td><x-inventory.status-badge :status="$writeOff->status" /></td>
                                <td class="text-right">
                                    @if (auth()->user()->can('inventory.write_offs.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('inventory.write_offs.edit', $writeOff) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('inventory.write_offs.delete'))
                                                <form method="POST" action="{{ route('inventory.write_offs.destroy', $writeOff) }}"
                                                    onsubmit="return confirm('Delete write-off {{ $writeOff->number }}?');">
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

        @if ($writeOffs->hasPages())
            <div class="px-5 py-4">
                {{ $writeOffs->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
