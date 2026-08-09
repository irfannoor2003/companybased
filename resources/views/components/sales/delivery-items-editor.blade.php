@props([
    'products' => [],
    'initialItems' => [],
])

<div
    x-data="{
        products: {{ \Illuminate\Support\Js::from($products->mapWithKeys(fn ($p) => [$p->id => ['name' => $p->name, 'sku' => $p->sku]])->all()) }},
        items: {{ \Illuminate\Support\Js::from($initialItems) }},
        addRow() {
            this.items.push({ product_id: '', description: '', qty: 1 });
        },
        removeRow(i) {
            this.items.splice(i, 1);
        },
        onProductChange(i) {
            const p = this.products[this.items[i].product_id];
            if (p) {
                this.items[i].description = p.name;
            }
        },
    }"
>
    <div class="mb-3 flex items-center justify-between gap-3">
        <p class="text-sm font-semibold text-ink">Delivered items</p>
        <x-button type="button" size="sm" variant="secondary" icon="plus" x-on:click="addRow()">Add item</x-button>
    </div>

    <template x-if="items.length === 0">
        <p class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-ink-faint">
            No items yet. Click “Add item” to list what is being delivered.
        </p>
    </template>

    <div class="space-y-2">
        <template x-for="(item, i) in items" :key="i">
            <div class="grid grid-cols-1 gap-2 rounded-lg border border-line bg-surface-muted/40 p-3 sm:grid-cols-[1fr_1.5fr_120px_40px]">
                <select :name="'items[' + i + '][product_id]'" x-model="item.product_id" x-on:change="onProductChange(i)" class="select-input">
                    <option value="">Manual / none</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}</option>
                    @endforeach
                </select>
                <input type="text" :name="'items[' + i + '][description]'" x-model="item.description" class="input" placeholder="Description…">
                <input type="number" step="any" min="0" :name="'items[' + i + '][qty]'" x-model="item.qty" class="input" placeholder="Qty">
                <button type="button" x-on:click="removeRow(i)" class="btn-ghost btn-icon btn-sm self-center text-rose-500" title="Remove">
                    <x-icon name="trash" class="size-4" />
                </button>
            </div>
        </template>
    </div>

    @error('items')
        <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
