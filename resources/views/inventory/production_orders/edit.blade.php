<x-app-layout :pageTitle="'Production order '.$order->number">
    <x-slot name="header">
        <x-page-header title="Production order {{ $order->number }}" description="{{ $order->item?->product?->name }} · {{ $order->warehouse?->name }}" icon="zap">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.production_orders.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Production order details">
                @if (in_array($order->status, ['completed', 'cancelled']))
                    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                        This order is {{ $order->status }} and cannot be edited. Stock movements have already been applied.
                    </div>
                @endif

                <form method="POST" action="{{ route('inventory.production_orders.update', $order) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <x-select name="item_id" label="Item to produce" required :disabled="in_array($order->status, ['completed', 'cancelled'])">
                            @foreach ($items as $entry)
                                <option value="{{ $entry->id }}" @selected(old('item_id', $order->item_id) == $entry->id)>{{ $entry->product?->name }}{{ $entry->product?->sku ? ' ('.$entry->product->sku.')' : '' }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="bill_of_material_id" label="Bill of materials" :disabled="in_array($order->status, ['completed', 'cancelled'])">
                            <option value="">— None —</option>
                            @foreach ($billOfMaterials as $bom)
                                <option value="{{ $bom->id }}" @selected(old('bill_of_material_id', $order->bill_of_material_id) == $bom->id)>{{ $bom->name }} ({{ $bom->item?->product?->name }})</option>
                            @endforeach
                        </x-select>
                        <x-select name="warehouse_id" label="Warehouse" required :disabled="in_array($order->status, ['completed', 'cancelled'])">
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $order->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="quantity" label="Quantity" type="number" step="0.001" min="0.001" required value="{{ old('quantity', $order->quantity) }}" :disabled="in_array($order->status, ['completed', 'cancelled'])" />
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-input name="scheduled_start_date" label="Scheduled start" type="date" value="{{ old('scheduled_start_date', $order->scheduled_start_date?->format('Y-m-d')) }}" :disabled="in_array($order->status, ['completed', 'cancelled'])" />
                        <x-input name="scheduled_end_date" label="Scheduled end" type="date" value="{{ old('scheduled_end_date', $order->scheduled_end_date?->format('Y-m-d')) }}" :disabled="in_array($order->status, ['completed', 'cancelled'])" />
                    </div>

                    <x-select name="status" label="Status" :disabled="in_array($order->status, ['completed', 'cancelled'])">
                        @foreach (\App\Models\InventoryProductionOrder::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', $order->status) === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>

                    @php
                        $initialItems = $order->items->map(fn ($item) => [
                            'item_id' => (string) ($item->component_item_id ?? ''),
                            'quantity' => (float) $item->quantity_required,
                            'quantity_used' => (float) $item->quantity_used,
                        ])->all();
                        $locked = in_array($order->status, ['completed', 'cancelled']);
                    @endphp

                    <div>
                        <p class="mb-3 text-sm font-semibold text-ink">Components</p>
                        <x-inventory.production-items-editor :items="$items" :initial-items="$initialItems" :locked="$locked" />
                        @if ($order->billOfMaterial)
                            <p class="mt-2 text-xs text-ink-faint">Components loaded from {{ $order->billOfMaterial->name }}.</p>
                        @endif
                    </div>

                    <x-textarea name="note" label="Note" rows="3" :disabled="$locked">{{ old('note', $order->note) }}</x-textarea>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        @if (! $locked)
                            <x-button type="submit" icon="save">Save changes</x-button>
                        @endif
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Status">
                <div class="flex items-center justify-between">
                    <x-inventory.status-badge :status="$order->status" />
                    @if (auth()->user()->can('inventory.production_orders.update_status') && $order->status !== 'completed')
                        <form method="POST" action="{{ route('inventory.production_orders.status', $order) }}" class="flex gap-2">
                            @csrf
                            @method('PATCH')
                            <x-select name="status" size="sm" class="w-32">
                                @foreach (\App\Models\InventoryProductionOrder::statusOptions() as $status)
                                    <option value="{{ $status }}" @selected($order->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </x-select>
                            <x-button type="submit" size="sm" variant="secondary">Update</x-button>
                        </form>
                    @endif
                </div>
                @if ($order->status === 'completed')
                    <p class="mt-3 text-xs text-ink-faint">Completing consumes components and adds the finished quantity to stock.</p>
                @endif
            </x-card>

            <x-card title="Summary">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Produce</dt>
                        <dd class="font-medium text-ink">{{ $order->quantity }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Components</dt>
                        <dd class="font-medium text-ink">{{ $order->items->count() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Total required</dt>
                        <dd class="font-medium text-ink">{{ $order->items->sum('quantity_required') }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
