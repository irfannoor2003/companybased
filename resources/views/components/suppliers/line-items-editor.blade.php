@props([
    'products' => [],
    'initialItems' => [],
    'currency' => null,
])

@php
    $productsMap = $products->mapWithKeys(fn ($p) => [
        $p->id => [
            'name' => $p->name,
            'description' => $p->name,
            'unit_price' => (float) $p->cost_price,
            'sku' => $p->sku,
        ],
    ])->all();

    $fmt = function ($v) use ($currency) {
        $code = $currency ?: settings('company.currency', 'USD');

        return (new NumberFormatter('en', NumberFormatter::CURRENCY))->formatCurrency((float) $v, $code);
    };
@endphp

<div
    x-data="supplierLineItems({
        currency: {{ \Illuminate\Support\Js::from($currency) }},
        products: {{ \Illuminate\Support\Js::from($productsMap) }},
        items: {{ \Illuminate\Support\Js::from($initialItems) }},
    })"
>
    <div class="mb-3 flex items-center justify-between gap-3">
        <p class="text-sm font-semibold text-ink">Line items</p>
        <x-button type="button" size="sm" variant="secondary" icon="plus" x-on:click="addRow()">Add line</x-button>
    </div>

    <template x-if="items.length === 0">
        <p class="rounded-lg border border-dashed border-line px-4 py-6 text-center text-sm text-ink-faint">
            No line items yet. Click “Add line” to build this document.
        </p>
    </template>

    <div class="space-y-2">
        <template x-for="(item, i) in items" :key="i">
            <div class="grid grid-cols-2 gap-2 rounded-lg border border-line bg-surface-muted/40 p-3 sm:grid-cols-7">
                <div class="col-span-2 sm:col-span-2">
                    <label class="field-label">Product</label>
                    <select
                        :name="'items[' + i + '][product_id]'"
                        x-model="item.product_id"
                        x-on:change="onProductChange(i)"
                        class="select-input"
                    >
                        <option value="">Manual / none</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-2">
                    <label class="field-label">Description</label>
                    <input type="text" :name="'items[' + i + '][description]'" x-model="item.description" class="input" placeholder="Line description…">
                </div>
                <div>
                    <label class="field-label">Qty</label>
                    <input type="number" step="any" min="0" :name="'items[' + i + '][qty]'" x-model="item.qty" class="input">
                </div>
                <div>
                    <label class="field-label">Unit price</label>
                    <input type="number" step="0.01" min="0" :name="'items[' + i + '][unit_price]'" x-model="item.unit_price" class="input">
                </div>
                <div>
                    <label class="field-label">Disc. %</label>
                    <input type="number" step="0.01" min="0" max="100" :name="'items[' + i + '][discount_percent]'" x-model="item.discount_percent" class="input">
                </div>
                <div>
                    <label class="field-label">Tax %</label>
                    <input type="number" step="0.01" min="0" max="100" :name="'items[' + i + '][tax_percent]'" x-model="item.tax_percent" class="input">
                </div>
                <div>
                    <label class="field-label">Net</label>
                    <p class="flex h-9 items-center text-sm font-medium text-ink" x-text="money(lineNet(i))"></p>
                </div>
                <div class="col-span-2 flex items-end justify-end sm:col-span-7">
                    <button type="button" x-on:click="removeRow(i)" class="btn-ghost btn-icon btn-sm text-rose-500" title="Remove">
                        <x-icon name="trash" class="size-4" />
                    </button>
                </div>
            </div>
        </template>
    </div>

    <div class="mt-4 flex justify-end">
        <div class="w-full max-w-xs space-y-1.5 rounded-lg border border-line bg-surface-muted/40 px-4 py-3 text-sm">
            <div class="flex justify-between text-ink-soft">
                <span>Subtotal</span>
                <span x-text="money(subtotal())"></span>
            </div>
            <div class="flex justify-between text-emerald-600" x-show="discountTotal() > 0">
                <span>Discount</span>
                <span x-text="'-' + money(discountTotal())"></span>
            </div>
            <div class="flex justify-between text-ink-soft">
                <span>Tax</span>
                <span x-text="money(tax())"></span>
            </div>
            <div class="flex justify-between border-t border-line pt-1.5 font-semibold text-ink">
                <span>Total</span>
                <span x-text="money(total())"></span>
            </div>
        </div>
    </div>

    @error('items')
        <p class="mt-1.5 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
