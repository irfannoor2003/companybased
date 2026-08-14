@props([
    'products' => [],
    'initialItems' => [],
    'locked' => false,
    'receiving' => false,
])

<div
    x-data="{
        items: {{ \Illuminate\Support\Js::from($initialItems) }},
        addRow() {
            this.items.push({ product_id: '', expected_quantity: '', received_quantity: '', unit_cost: '', notes: '' });
        },
        removeRow(i) {
            this.items.splice(i, 1);
        }
    }"
>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-semibold text-ink">Items expected</p>
        @if (! $locked)
            <x-button size="sm" variant="secondary" type="button" x-on:click="addRow()" icon="plus">Add item</x-button>
        @endif
    </div>

    <template x-if="items.length === 0">
        <p class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-ink-faint">
            No items yet. Click “Add item” to list what is expected in this shipment.
        </p>
    </template>

    <div class="space-y-2">
        <template x-for="(item, i) in items" :key="i">
            <div class="grid grid-cols-12 gap-2 items-end rounded-lg border border-line bg-surface-muted/40 p-3 sm:gap-3">
                <div class="col-span-12 sm:col-span-4">
                    <label class="label">Product</label>
                    <select :name="'items[' + i + '][product_id]'" x-model="item.product_id" :disabled="{{ $locked ? 'true' : 'false' }}" class="select-input">
                        <option value="">— Select product —</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-span-6 sm:col-span-2">
                    <label class="label">Expected</label>
                    <input type="number" step="0.001" min="0" :name="'items[' + i + '][expected_quantity]'" x-model="item.expected_quantity" :disabled="{{ $locked ? 'true' : 'false' }}" class="input" placeholder="0">
                </div>

                <div class="col-span-6 sm:col-span-2" x-show="{{ $receiving ? 'true' : 'false' }}">
                    <label class="label">Received</label>
                    <input type="number" step="0.001" min="0" :name="'items[' + i + '][received_quantity]'" x-model="item.received_quantity" :disabled="{{ $locked ? 'true' : 'false' }}" class="input" placeholder="0">
                </div>

                <div class="col-span-6 sm:col-span-2">
                    <label class="label">Unit cost</label>
                    <input type="number" step="0.01" min="0" :name="'items[' + i + '][unit_cost]'" x-model="item.unit_cost" :disabled="{{ $locked ? 'true' : 'false' }}" class="input" placeholder="—">
                </div>

                <div class="col-span-12 sm:col-span-3">
                    <label class="label">Notes</label>
                    <input type="text" :name="'items[' + i + '][notes]'" x-model="item.notes" :disabled="{{ $locked ? 'true' : 'false' }}" class="input" placeholder="Optional">
                </div>

                @if (! $locked)
                    <div class="col-span-12 sm:col-span-1 flex justify-end">
                        <button type="button" x-on:click="removeRow(i)" class="btn-ghost btn-icon btn-sm text-rose-500" title="Remove">
                            <x-icon name="trash" class="size-4" />
                        </button>
                    </div>
                @endif
            </div>
        </template>
    </div>

    @error('items')
        <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
