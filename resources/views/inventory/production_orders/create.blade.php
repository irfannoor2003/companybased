<x-app-layout :pageTitle="'New production order'">
    <x-slot name="header">
        <x-page-header title="New production order" description="Plan a manufacturing run." icon="zap">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.production_orders.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Production order details">
            <form method="POST" action="{{ route('inventory.production_orders.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <x-select name="item_id" label="Item to produce" required>
                        <option value="">— Select item —</option>
                        @foreach ($items as $entry)
                            <option value="{{ $entry->id }}" @selected(old('item_id') == $entry->id)>{{ $entry->product?->name }}{{ $entry->product?->sku ? ' ('.$entry->product->sku.')' : '' }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="bill_of_material_id" label="Bill of materials">
                        <option value="">— None —</option>
                        @foreach ($billOfMaterials as $bom)
                            <option value="{{ $bom->id }}" @selected(old('bill_of_material_id') == $bom->id)>{{ $bom->name }} ({{ $bom->item?->product?->name }})</option>
                        @endforeach
                    </x-select>
                    <x-select name="warehouse_id" label="Warehouse" required>
                        <option value="">— Select —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="quantity" label="Quantity" type="number" step="0.001" min="0.001" required value="{{ old('quantity', 1) }}" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="scheduled_start_date" label="Scheduled start" type="date" value="{{ old('scheduled_start_date') }}" />
                    <x-input name="scheduled_end_date" label="Scheduled end" type="date" value="{{ old('scheduled_end_date') }}" />
                </div>

                <x-select name="status" label="Status">
                    @foreach (\App\Models\InventoryProductionOrder::statusOptions() as $status)
                        <option value="{{ $status }}" @selected(old('status', 'draft') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </x-select>

                <div>
                    <p class="mb-3 text-sm font-semibold text-ink">Components</p>
                    <x-inventory.production-items-editor :items="$items" />
                    <p class="mt-2 text-xs text-ink-faint">If a bill of materials is selected, its components are loaded automatically instead of the lines below.</p>
                </div>

                <x-textarea name="note" label="Note" rows="3">{{ old('note') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('inventory.production_orders.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create order</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
