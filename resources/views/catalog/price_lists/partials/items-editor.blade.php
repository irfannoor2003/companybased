@props([
    'products' => [],
    'initialItems' => [],
])

<div
    x-data="{
        items: {{ \Illuminate\Support\Js::from($initialItems) }},
        addRow() {
            this.items.push({ product_id: '', price: '' });
        },
        removeRow(i) {
            this.items.splice(i, 1);
        },
    }"
>
    <div class="mb-3 flex items-center justify-between gap-3">
        <p class="text-sm font-semibold text-ink">Product prices</p>
        <x-button type="button" size="sm" variant="secondary" icon="plus" x-on:click="addRow()">Add product</x-button>
    </div>

    <template x-if="items.length === 0">
        <p class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-ink-faint">
            No products added yet. Click “Add product” to include prices.
        </p>
    </template>

    <div class="space-y-2">
        <template x-for="(item, i) in items" :key="i">
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_180px_40px]">
                <select
                    :name="'items[' + i + '][product_id]'"
                    x-model="item.product_id"
                    class="select-input"
                >
                    <option value="">Select product…</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}</option>
                    @endforeach
                </select>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    :name="'items[' + i + '][price]'"
                    x-model="item.price"
                    placeholder="0.00"
                    class="input"
                >
                <button
                    type="button"
                    x-on:click="removeRow(i)"
                    class="btn-ghost btn-icon btn-sm self-center text-rose-500"
                    title="Remove"
                >
                    <x-icon name="trash" class="size-4" />
                </button>
            </div>
        </template>
    </div>

    @error('items')
        <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
