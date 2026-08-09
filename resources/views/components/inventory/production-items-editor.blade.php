@props([
    'items' => [],
    'initialItems' => [],
    'locked' => false,
])

<div
    x-data="{
        items: {{ \Illuminate\Support\Js::from($initialItems) }},
        addRow() {
            this.items.push({ item_id: '', quantity: 1, quantity_used: 0 });
        },
        removeRow(i) {
            this.items.splice(i, 1);
        },
    }"
>
    <div class="mb-3 flex items-center justify-between gap-3">
        <p class="text-sm font-semibold text-ink">Components consumed</p>
        @if (! $locked)
            <x-button type="button" size="sm" variant="secondary" icon="plus" x-on:click="addRow()">Add component</x-button>
        @endif
    </div>

    <template x-if="items.length === 0">
        <p class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-ink-faint">
            No components yet. Pick a bill of materials or add components manually.
        </p>
    </template>

    <div class="space-y-2">
        <template x-for="(item, i) in items" :key="i">
            <div class="grid grid-cols-1 gap-2 rounded-lg border border-line bg-surface-muted/40 p-3 sm:grid-cols-[2fr_140px_140px_40px]">
                <select :name="'items[' + i + '][item_id]'" x-model="item.item_id" :disabled="{{ $locked ? 'true' : 'false' }}" class="select-input">
                    <option value="">— Select component —</option>
                    @foreach ($items as $entry)
                        <option value="{{ $entry->id }}">{{ $entry->product?->name }}{{ $entry->product?->sku ? ' ('.$entry->product->sku.')' : '' }}</option>
                    @endforeach
                </select>
                <input type="number" step="0.001" min="0" :name="'items[' + i + '][quantity]'" x-model="item.quantity" :disabled="{{ $locked ? 'true' : 'false' }}" class="input" placeholder="Qty req.">
                <input type="number" step="0.001" min="0" :name="'items[' + i + '][quantity_used]'" x-model="item.quantity_used" :disabled="{{ $locked ? 'true' : 'false' }}" class="input" placeholder="Qty used">
                @if (! $locked)
                    <button type="button" x-on:click="removeRow(i)" class="btn-ghost btn-icon btn-sm self-center text-rose-500" title="Remove">
                        <x-icon name="trash" class="size-4" />
                    </button>
                @endif
            </div>
        </template>
    </div>

    @error('items')
        <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
