@props([
    'type' => 'Invoice',
    'number' => 'Draft',
    'customerName' => '',
    'issueDate' => '',
    'dueDate' => '',
    'currency' => 'USD',
    'notes' => '',
])

<div
    x-data="documentPreview({
        customerName: @js($customerName),
        issueDate: @js($issueDate),
        dueDate: @js($dueDate),
        currency: @js($currency),
        notes: @js($notes),
        fallbackCurrency: @js(settings('company.currency', 'USD')),
    })"
    x-on:click.away="show = false"
>
    <x-button type="button" variant="secondary" icon="eye" x-on:click="refreshPreview(); show = !show" x-text="show ? 'Hide preview' : 'Show preview'">Show preview</x-button>

    <div
        x-show="show"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-x-4"
        x-transition:enter-end="opacity-100 translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-x-0"
        x-transition:leave-end="opacity-0 translate-x-4"
        class="fixed right-0 top-0 z-50 flex h-full w-full max-w-2xl flex-col border-l border-line bg-surface shadow-xl print:hidden"
    >
        <div class="flex items-center justify-between border-b border-line px-6 py-3">
            <h3 class="text-sm font-semibold text-ink">Document preview</h3>
            <div class="flex items-center gap-2">
                <x-button type="button" size="sm" variant="secondary" icon="refresh" x-on:click="refreshPreview()">Refresh</x-button>
                <button type="button" x-on:click="show = false" class="btn-ghost btn-icon btn-sm">
                    <x-icon name="close" class="size-4" />
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div class="mx-auto max-w-xl rounded-lg border border-line bg-white p-6 shadow-sm">
                {{-- Header --}}
                <div class="mb-6 flex justify-between border-b border-line pb-4">
                    <div>
                        <p class="text-lg font-bold text-ink">{{ company_name() }}</p>
                        <p class="text-xs text-ink-faint">{{ settings('company.address') ?: '' }}</p>
                        <p class="text-xs text-ink-faint">{{ settings('company.email') ?: '' }}</p>
                    </div>
                    <div class="text-right">
                        <h2 class="text-xl font-bold text-ink">{{ $type }}</h2>
                        <p class="text-xs text-ink-soft">{{ $number }}</p>
                        <p class="text-xs text-ink-faint">Date: <span x-text="issueDate || '—'"></span></p>
                        @if ($type === 'Invoice' || $type === 'Credit Note')
                            <p class="text-xs text-ink-faint">Due: <span x-text="dueDate || '—'"></span></p>
                        @endif
                    </div>
                </div>

                {{-- Bill To --}}
                <div class="mb-6">
                    <p class="mb-1 text-xs font-semibold uppercase text-ink-faint">Bill to</p>
                    <p class="text-sm font-medium text-ink" x-text="customerName || '—'"></p>
                </div>

                {{-- Line Items --}}
                <table class="mb-6 w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-line">
                            <th class="pb-2 pr-2 font-semibold text-ink-faint">#</th>
                            <th class="pb-2 pr-2 font-semibold text-ink-faint">Description</th>
                            <th class="pb-2 pr-2 text-right font-semibold text-ink-faint">Qty</th>
                            <th class="pb-2 pr-2 text-right font-semibold text-ink-faint">Price</th>
                            <th class="pb-2 pr-2 text-right font-semibold text-ink-faint">Disc.</th>
                            <th class="pb-2 pr-2 text-right font-semibold text-ink-faint">Tax</th>
                            <th class="pb-2 text-right font-semibold text-ink-faint">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-if="items.length === 0">
                            <tr>
                                <td colspan="7" class="py-4 text-center text-ink-faint">No line items yet</td>
                            </tr>
                        </template>
                        <template x-for="(item, i) in items" :key="i">
                            <tr class="border-b border-line/50">
                                <td class="py-2 pr-2 text-ink-faint" x-text="i + 1"></td>
                                <td class="py-2 pr-2 text-ink" x-text="item.description || '—'"></td>
                                <td class="py-2 pr-2 text-right text-ink" x-text="item.qty.toFixed(2)"></td>
                                <td class="py-2 pr-2 text-right text-ink" x-text="money(item.unit_price)"></td>
                                <td class="py-2 pr-2 text-right" :class="item.discount > 0 ? 'text-emerald-600' : 'text-ink-faint'" x-text="item.discount > 0 ? '-' + money(item.discount) : '—'"></td>
                                <td class="py-2 pr-2 text-right text-ink" x-text="item.tax_percent ? item.tax_percent + '%' : '—'"></td>
                                <td class="py-2 text-right font-medium text-ink" x-text="money(item.total)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                {{-- Totals --}}
                <div class="flex justify-end">
                    <div class="w-48 space-y-1 text-xs">
                        <div class="flex justify-between text-ink-soft">
                            <span>Subtotal</span>
                            <span x-text="money(grossTotal())"></span>
                        </div>
                        <div class="flex justify-between text-emerald-600" x-show="hasDiscounts()">
                            <span>Discount</span>
                            <span x-text="'-' + money(discountTotal())"></span>
                        </div>
                        <div class="flex justify-between text-ink-soft">
                            <span>Tax</span>
                            <span x-text="money(taxTotal())"></span>
                        </div>
                        <div class="flex justify-between border-t border-line pt-1.5 text-sm font-semibold text-ink">
                            <span>Total</span>
                            <span x-text="money(grandTotal())"></span>
                        </div>
                    </div>
                </div>

                {{-- Notes --}}
                <div class="mt-6" x-show="notes">
                    <p class="mb-1 text-xs font-semibold uppercase text-ink-faint">Notes</p>
                    <p class="text-xs text-ink" x-text="notes"></p>
                </div>

                <div class="mt-6 border-t border-line pt-3 text-center text-[10px] text-ink-faint">
                    <p>Thank you for your business.</p>
                </div>
            </div>
        </div>
    </div>
</div>
