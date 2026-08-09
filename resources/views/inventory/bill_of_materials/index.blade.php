<x-app-layout :pageTitle="'Bill of Materials'">
    <x-slot name="header">
        <x-page-header title="Bill of Materials" description="Define what goes into making a finished item." icon="document">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.bill_of_materials.export'))
                    <x-export route="inventory.bill_of_materials.export" />
                @endif
                @if (auth()->user()->can('inventory.bill_of_materials.create'))
                    <x-button href="{{ route('inventory.bill_of_materials.create') }}" icon="plus">New BOM</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card :padding="false">
        <form method="GET" action="{{ route('inventory.bill_of_materials.index') }}" class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4">
            <div class="min-w-[220px] flex-1">
                <x-input name="search" label="Search" placeholder="BOM name…" leadingIcon="search"
                    value="{{ request('search') }}" size="sm" />
            </div>
            <div class="w-40">
                <x-select name="status" label="Status" size="sm">
                    <option value="">Any status</option>
                    @foreach (\App\Models\InventoryBillOfMaterial::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>
            </div>
            <div class="flex gap-2">
                <x-button type="submit" size="sm" icon="filter">Filter</x-button>
                @if (request()->hasAny(['search', 'status']))
                    <x-button href="{{ route('inventory.bill_of_materials.index') }}" variant="ghost" size="sm">Clear</x-button>
                @endif
            </div>
        </form>

        @if ($billOfMaterials->isEmpty())
            <x-empty-state icon="document" title="No bills of materials" description="Create a BOM to define production recipes." />
        @else
            <div class="table-wrap !border-0 !rounded-none">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Finished item</th>
                            <th>Version</th>
                            <th class="text-right">Components</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($billOfMaterials as $bom)
                            <tr>
                                <td class="font-medium text-ink">{{ $bom->name }}</td>
                                <td class="text-ink-soft">{{ $bom->item?->product?->name }}</td>
                                <td class="text-ink-soft">{{ $bom->version }}</td>
                                <td class="text-right text-ink-soft">{{ $bom->items_count ?? $bom->items()->count() }}</td>
                                <td><x-inventory.status-badge :status="$bom->status" /></td>
                                <td class="text-right">
                                    @if (auth()->user()->can('inventory.bill_of_materials.edit'))
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('inventory.bill_of_materials.edit', $bom) }}" class="btn-ghost btn-icon btn-sm" title="Edit">
                                                <x-icon name="edit" class="size-4" />
                                            </a>
                                            @if (auth()->user()->can('inventory.bill_of_materials.delete'))
                                                <form method="POST" action="{{ route('inventory.bill_of_materials.destroy', $bom) }}"
                                                    onsubmit="return confirm('Delete bill of materials {{ $bom->name }}?');">
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

        @if ($billOfMaterials->hasPages())
            <div class="px-5 py-4">
                {{ $billOfMaterials->links() }}
            </div>
        @endif
    </x-card>
</x-app-layout>
