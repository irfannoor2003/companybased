@props([
    'type' => 'Invoice',
    'number' => 'Draft',
    'customerName' => '',
    'issueDate' => '',
    'dueDate' => '',
    'currency' => 'USD',
    'notes' => '',
])

{{--
    The trigger stays inline, in the page header next to the form's buttons.

    The overlay itself is pushed to the `preview` stack, which the layout renders
    *outside* <main>. That placement is not cosmetic: to print the document
    instead of the data-entry form, the print stylesheet has to hide <main>, and
    while this overlay was inside <main> that hid the document along with the
    form and printed a blank page.
--}}
<div
    x-data="documentPreview({
        type: @js($type),
        partyLabel: @js(str_starts_with($type, 'Purchase') || $type === 'Debit Note' ? 'Supplier' : 'Bill to'),
        footer: @js($type === 'Delivery Note' ? 'Prepared for shipment.' : 'Thank you for your business.'),
        pricing: @js($type !== 'Delivery Note'),
        showDueDate: @js(in_array($type, ['Invoice', 'Purchase Invoice', 'Credit Note'], true)),
        customerName: @js($customerName),
        issueDate: @js($issueDate),
        dueDate: @js($dueDate),
        currency: @js($currency),
        notes: @js($notes),
        fallbackCurrency: @js(settings('company.currency', 'USD')),
        isOverlay: false,
    })"
>
    <x-button type="button" variant="secondary" icon="eye" x-on:click="show = !show" x-text="show ? 'Hide preview' : 'Show preview'">Show preview</x-button>
</div>

@push('preview')
    <div
        x-data="documentPreview({
            type: @js($type),
            partyLabel: @js(str_starts_with($type, 'Purchase') || $type === 'Debit Note' ? 'Supplier' : 'Bill to'),
            footer: @js($type === 'Delivery Note' ? 'Prepared for shipment.' : 'Thank you for your business.'),
            pricing: @js($type !== 'Delivery Note'),
            showDueDate: @js(in_array($type, ['Invoice', 'Purchase Invoice', 'Credit Note'], true)),
            customerName: @js($customerName),
            issueDate: @js($issueDate),
            dueDate: @js($dueDate),
            currency: @js($currency),
            notes: @js($notes),
            fallbackCurrency: @js(settings('company.currency', 'USD')),
            isOverlay: true,
        })"
        x-show="show"
        x-cloak
        @keydown.escape.window="show = false"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="document-preview-overlay fixed inset-0 z-50 overflow-y-auto bg-slate-900/70"
        role="dialog"
        aria-modal="true"
        aria-label="Document preview"
    >
        <div class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface px-4 py-3 shadow-sm sm:px-6">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-ink">{{ $type }} preview</h3>
                <p class="truncate text-xs text-ink-faint">Nothing is saved until you save the form.</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <x-button type="button" size="sm" variant="secondary" icon="print" x-on:click="printPreview()">Print</x-button>
                <x-button type="button" size="sm" variant="secondary" icon="close" x-on:click="show = false">Close</x-button>
            </div>
        </div>

        {{-- The sheet.

             Laid out to mirror resources/views/sales/invoices/partials/pdf-rail.blade.php:
             the same dark rail down the left, the same letterspaced title, the same
             hero "Total Due", the same boxed table header and boxed total row.

             The rail colour is computed by the *same* helper the PDF uses rather
             than by a Tailwind token. The preview and the PDF are necessarily two
             implementations — DomPDF cannot run Tailwind or Alpine — but they must
             not be two different designs, and they certainly must not be two
             different colours for the same brand.
        --}}
        <div class="flex justify-center px-4 py-8 sm:px-6">
            <div class="document-preview-sheet flex w-full max-w-4xl overflow-hidden rounded-lg bg-surface text-ink shadow-2xl">
                {{-- Rail --}}
                <div class="hidden w-60 shrink-0 flex-col px-6 py-8 text-white md:flex"
                     style="background-color: {{ darken_hex(settings('branding.primary_color', '#4f46e5'), 62) }};">

                    @if (settings('branding.logo'))
                        <img src="{{ Storage::url(settings('branding.logo')) }}" alt="{{ company_name() }}" class="mb-3 max-h-9 max-w-24">
                    @endif
                    <p class="text-base font-bold leading-tight">{{ company_name() }}</p>
                    @if (settings('company.tagline'))
                        <p class="mt-1 text-[10px] tracking-wide text-white/70">{{ settings('company.tagline') }}</p>
                    @endif

                    <div class="my-4 border-t border-white/30"></div>

                    <p class="mb-1.5 text-[10px] font-bold uppercase tracking-widest text-white/70" x-text="partyLabel"></p>
                    <p class="text-xs font-bold" x-text="customerName || '—'"></p>

                    {{-- Only when filled: an empty heading promises information that
                         is not there. Matches the PDF's condition exactly. --}}
                    @if (settings('company.bank_account_name') || settings('company.bank_account_number') || settings('company.payment_instructions'))
                        <div class="my-4 border-t border-white/30"></div>
                        <p class="mb-1.5 text-[10px] font-bold uppercase tracking-widest text-white/70">Payment</p>
                        @if (settings('company.bank_account_name'))
                            <p class="text-xs font-bold">{{ settings('company.bank_account_name') }}</p>
                        @endif
                        @if (settings('company.bank_name'))
                            <p class="text-[10px] text-white/70">{{ settings('company.bank_name') }}</p>
                        @endif
                        @if (settings('company.bank_account_number'))
                            <p class="text-[10px] text-white/70">A/C {{ settings('company.bank_account_number') }}</p>
                        @endif
                        @if (settings('company.payment_instructions'))
                            <p class="whitespace-pre-line text-[10px] text-white/70">{{ settings('company.payment_instructions') }}</p>
                        @endif
                    @endif

                    @if (settings('company.payment_terms'))
                        <div class="my-4 border-t border-white/30"></div>
                        <p class="mb-1.5 text-[10px] font-bold uppercase tracking-widest text-white/70">Terms</p>
                        <p class="whitespace-pre-line text-[10px] leading-relaxed text-white/70">{{ settings('company.payment_terms') }}</p>
                    @endif

                    <p class="mt-auto pt-8 text-xs font-bold uppercase leading-relaxed tracking-widest">Thank you<br>for your business</p>
                </div>

                {{-- Canvas --}}
                <div class="min-w-0 flex-1 px-6 py-8 sm:px-10">
                    <p class="text-2xl font-light uppercase tracking-[0.22em] text-ink sm:text-3xl" x-text="type"></p>
                    <p class="mt-1 text-xs text-ink-faint"><span x-text="type === 'Quote' ? 'Quote#' : (type === 'Purchase Order' ? 'PO#' : type === 'Delivery Note' ? 'DN#' : 'Invoice#')"></span> {{ $number }}</p>

                    <div class="mt-10 text-sm text-ink">Date: <span x-text="issueDate || '—'"></span></div>

                    <template x-if="pricing">
                        <div class="mt-5">
                            <p class="text-sm text-ink-faint">Total Due</p>
                            <p class="mt-0.5 text-xl font-bold text-ink" x-text="money(grandTotal())"></p>
                            <p class="mt-1 text-[11px] text-ink-faint" x-show="showDueDate">
                                Due: <span x-text="dueDate || '—'"></span>
                            </p>
                        </div>
                    </template>

                    <table class="mt-9 w-full text-left text-xs">
                        <thead>
                            {{-- Boxed header, as on the PDF --}}
                            <tr>
                                <th class="border border-ink px-2 py-1.5 font-bold uppercase tracking-wider text-ink">#</th>
                                <th class="border border-ink px-2 py-1.5 font-bold uppercase tracking-wider text-ink">Description</th>
                                <th class="border border-ink px-2 py-1.5 text-right font-bold uppercase tracking-wider text-ink">Qty</th>
                                <template x-if="pricing">
                                    <th class="border border-ink px-2 py-1.5 text-right font-bold uppercase tracking-wider text-ink">Price</th>
                                </template>
                                <template x-if="pricing">
                                    <th class="border border-ink px-2 py-1.5 text-right font-bold uppercase tracking-wider text-ink">Disc.</th>
                                </template>
                                <template x-if="pricing">
                                    <th class="border border-ink px-2 py-1.5 text-right font-bold uppercase tracking-wider text-ink">Tax</th>
                                </template>
                                <th class="border border-ink px-2 py-1.5 text-right font-bold uppercase tracking-wider text-ink" x-text="pricing ? 'Total' : 'Total Qty'"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-if="items.length === 0">
                                <tr>
                                    <td class="py-6 text-center text-ink-faint" x-text="pricing ? 'No line items yet' : 'No items yet'"></td>
                                </tr>
                            </template>
                            <template x-for="(item, i) in items" :key="i">
                                <tr class="border-b border-line">
                                    <td class="py-2.5 px-2 text-ink-faint" x-text="i + 1"></td>
                                    <td class="py-2.5 px-2 text-ink" x-text="item.description || '—'"></td>
                                    <td class="py-2.5 px-2 text-right tabular-nums text-ink" x-text="item.qty.toFixed(2)"></td>
                                    <template x-if="pricing">
                                        <td class="py-2.5 px-2 text-right tabular-nums text-ink" x-text="money(item.unit_price)"></td>
                                    </template>
                                    <template x-if="pricing">
                                        <td class="py-2.5 px-2 text-right tabular-nums" :class="item.discount > 0 ? 'text-emerald-600' : 'text-ink-faint'" x-text="item.discount > 0 ? '-' + money(item.discount) : '—'"></td>
                                    </template>
                                    <template x-if="pricing">
                                        <td class="py-2.5 px-2 text-right tabular-nums text-ink" x-text="item.tax_percent ? item.tax_percent + '%' : '—'"></td>
                                    </template>
                                    <td class="py-2.5 px-2 text-right font-medium tabular-nums text-ink" x-text="pricing ? money(item.total) : item.qty.toFixed(2)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <div class="mt-4 flex justify-end" x-show="pricing">
                        <dl class="w-64 space-y-1 text-xs">
                            <div class="flex justify-between">
                                <dt class="text-ink-faint">Subtotal</dt>
                                <dd class="tabular-nums text-ink" x-text="money(grossTotal())"></dd>
                            </div>
                            <div class="flex justify-between" x-show="hasDiscounts()">
                                <dt class="text-ink-faint">Discount</dt>
                                <dd class="tabular-nums text-emerald-600" x-text="'-' + money(discountTotal())"></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-ink-faint">Tax</dt>
                                <dd class="tabular-nums text-ink" x-text="money(taxTotal())"></dd>
                            </div>
                            {{-- Boxed total row, as on the PDF --}}
                            <div class="mt-1 flex justify-between border border-primary px-2 py-1.5 text-sm">
                                <dt class="font-bold text-primary-ink">Total</dt>
                                <dd class="font-bold tabular-nums text-primary-ink" x-text="money(grandTotal())"></dd>
                            </div>
                        </dl>
                    </div>

                    <div class="mt-6" x-show="notes">
                        <div class="bg-surface-muted p-3">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-ink-faint">Notes</p>
                            <p class="mt-1 whitespace-pre-line text-xs leading-relaxed text-ink-soft" x-text="notes"></p>
                        </div>
                    </div>

                    <div class="mt-8 border-t border-line pt-3 text-[10px] leading-relaxed text-ink-faint">
                        <div class="flex flex-wrap gap-x-6 gap-y-1">
                            <span class="whitespace-pre-line">{{ settings('company.address') }}</span>
                            @if (settings('company.phone'))
                                <span>P: {{ settings('company.phone') }}</span>
                            @endif
                            @if (settings('company.email'))
                                <span>M: {{ settings('company.email') }}</span>
                            @endif
                            @if (settings('company.website'))
                                <span>{{ settings('company.website') }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endpush
