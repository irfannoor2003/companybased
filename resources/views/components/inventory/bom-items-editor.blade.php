@props([
    'items' => [],
    'initialItems' => [],
])

<div
    x-data="{
        items: {{ \Illuminate\Support\Js::from($initialItems) }},
        addRow() {
            this.items.push({ item_id: '', quantity: 1, wastage_percent: 0 });
        },
        removeRow(i) {
            this.items.splice(i, 1);
        },
    }"
>
    <div class="mb-3 flex items-center justify-between gap-3">
        <p class="text-sm font-semibold text-ink">Component items</p>
        <x-button type="button" size="sm" variant="secondary" icon="plus" x-on:click="addRow()">Add component</x-button>
    </div>

    <template x-if="items.length === 0">
        <p class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-ink-faint">
            No components yet. Click “Add component” to define what goes into the finished item.
        </p>
    </template>

    <div class="space-y-2">
        <template x-for="(item, i) in items" :key="i">
            <div class="grid grid-cols-1 gap-2 rounded-lg border border-line bg-surface-muted/40 p-3 sm:grid-cols-[2fr_140px_140px_40px]">
                <select :name="'items[' + i + '][item_id]'" x-model="item.item_id" class="select-input">
                    <option value="">— Select component —</option>
                    @foreach ($items as $entry)
                        <option value="{{ $entry->id }}">{{ $entry->product?->name }}{{ $entry->product?->sku ? ' ('.$entry->product->sku.')' : '' }}</option>
                    @endforeach
                </select>
                <input type="number" step="0.001" min="0.001" :name="'items[' + i + '][quantity]'" x-model="item.quantity" class="input" placeholder="Qty per unit">
                <input type="number" step="0.01" min="0" max="100" :name="'items[' + i + '][wastage_percent]'" x-model="item.wastage_percent" class="input" placeholder="Wastage %">
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
