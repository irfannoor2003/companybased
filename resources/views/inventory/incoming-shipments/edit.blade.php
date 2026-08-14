<x-app-layout :pageTitle="'Edit shipment '.$shipment->number">
    <x-slot name="header">
        <x-page-header title="Incoming shipment {{ $shipment->number }}" description="{{ $shipment->supplier?->name ?? $shipment->warehouse?->name }}" icon="truck">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.incoming_shipments.show', $shipment) }}" variant="secondary" icon="arrow-left">Back to shipment</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <x-card title="Shipment details">
            <form method="POST" action="{{ route('inventory.incoming_shipments.update', $shipment) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <x-select name="supplier_id" label="Supplier" size="sm">
                        <option value="">— None —</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $shipment->supplier_id) == $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </x-select>

                    <x-select name="warehouse_id" label="Destination warehouse" required size="sm">
                        <option value="">— Select warehouse —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $shipment->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>

                    <x-select name="purchase_order_id" label="Related purchase order" size="sm">
                        <option value="">— None —</option>
                        @foreach ($purchaseOrders as $order)
                            <option value="{{ $order->id }}" @selected(old('purchase_order_id', $shipment->purchase_order_id) == $order->id)>
                                {{ $order->number }} · {{ $order->supplier?->name }}
                            </option>
                        @endforeach
                    </x-select>

                    <x-input name="expected_arrival_at" label="Expected arrival" type="date" value="{{ old('expected_arrival_at', $shipment->expected_arrival_at?->format('Y-m-d')) }}" size="sm" />
                </div>

                <x-select name="status" label="Status" size="sm">
                    @foreach (\App\Models\InventoryIncomingShipment::statusOptions() as $status)
                        @if ($status === 'approved') @continue @endif
                        <option value="{{ $status }}" @selected(old('status', $shipment->status) === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </x-select>

                @php
                    $initialItems = $shipment->items->map(fn ($item) => [
                        'product_id' => (string) ($item->product_id ?? ''),
                        'expected_quantity' => (float) $item->expected_quantity,
                        'received_quantity' => (float) $item->received_quantity,
                        'unit_cost' => $item->unit_cost ? (float) $item->unit_cost : '',
                        'notes' => $item->notes ?? '',
                    ])->all();
                    $receiving = in_array(old('status', $shipment->status), ['in_transit', 'arrived'], true);
                @endphp

                <x-inventory.incoming-shipment-items-editor
                    :products="$trackedProducts"
                    :initial-items="$initialItems"
                    :locked="false"
                    :receiving="$receiving"
                />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $shipment->notes) }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('inventory.incoming_shipments.show', $shipment) }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
