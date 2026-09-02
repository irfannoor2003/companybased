<x-app-layout :pageTitle="'New incoming shipment'">
    <x-slot name="header">
        <x-page-header title="New incoming shipment" description="Track stock expected from a supplier into a warehouse." icon="truck">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.incoming_shipments.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <x-card title="Shipment details">
            <form method="POST" action="{{ route('inventory.incoming_shipments.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <x-select name="supplier_id" label="Supplier" size="sm">
                        <option value="">— None —</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->company_name }}</option>
                        @endforeach
                    </x-select>

                    <x-select name="warehouse_id" label="Destination warehouse" required size="sm">
                        <option value="">— Select warehouse —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>

                    <x-select name="purchase_order_id" label="Related purchase order" size="sm">
                        <option value="">— None —</option>
                        @foreach ($purchaseOrders as $order)
                            <option value="{{ $order->id }}" @selected(old('purchase_order_id') == $order->id)>
                                {{ $order->number }} · {{ $order->supplier?->company_name }}
                            </option>
                        @endforeach
                    </x-select>

                    <x-input name="expected_arrival_at" label="Expected arrival" type="date" value="{{ old('expected_arrival_at') }}" size="sm" />
                </div>

                <x-select name="status" label="Initial status" size="sm">
                    <option value="pending" @selected(old('status', 'pending') === 'pending')">Pending</option>
                    <option value="in_transit" @selected(old('status') === 'in_transit')">In transit</option>
                </x-select>

                @php
                    $initialItems = old('items') ?? [];
                @endphp

                <x-inventory.incoming-shipment-items-editor
                    :products="$trackedProducts"
                    :initial-items="$initialItems"
                    :locked="false"
                    :receiving="false"
                />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('inventory.incoming_shipments.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create shipment</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
