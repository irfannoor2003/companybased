<x-app-layout :pageTitle="'Edit purchase quote '.$quote->number">
    <x-slot name="header">
        <x-page-header title="Purchase quote {{ $quote->number }}" description="{{ $quote->supplier?->company_name }}" icon="document">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Purchase Quote" number="{{ $quote->number }}" customerName="{{ $quote->supplier?->company_name }}" issueDate="{{ $quote->issue_date }}" currency="{{ $quote->currency }}" notes="{{ $quote->notes }}" />
                    @if (auth()->user()->can('suppliers.purchase_quotes.convert') && ! $quote->isConverted())
                        <form method="POST" action="{{ route('suppliers.purchase_quotes.convert', $quote) }}" class="inline">
                            @csrf
                            <x-button type="submit" variant="secondary" icon="refresh">Convert to order</x-button>
                        </form>
                    @endif
                    <x-button href="{{ route('suppliers.purchase_quotes.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Quote details">
                <form method="POST" action="{{ route('suppliers.purchase_quotes.update', $quote) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                        <x-select name="supplier_id" label="Supplier" required :disabled="$quote->isConverted()" @change="$event.target.value && syncCurrency($event.target)">
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" data-currency="{{ $supplier->currency ?? '' }}" @selected(old('supplier_id', $quote->supplier_id) == $supplier->id)>{{ $supplier->company_name }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="status" label="Status" :disabled="$quote->isConverted()">
                            @foreach (\App\Models\PurchaseQuote::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(old('status', $quote->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', $quote->issue_date?->format('Y-m-d')) }}" :disabled="$quote->isConverted()" />
                        <x-input name="valid_until" label="Valid until" type="date" value="{{ old('valid_until', $quote->valid_until?->format('Y-m-d')) }}" :disabled="$quote->isConverted()" />
                        <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', 'currency', $quote->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    </div>

                    @php
                        $initialItems = $quote->items->map(fn ($item) => [
                            'product_id' => (string) ($item->product_id ?? ''),
                            'description' => $item->description,
                            'qty' => (float) $item->qty,
                            'unit_price' => (float) $item->unit_price,
                            'discount_percent' => (float) $item->discount_percent,
                            'tax_percent' => (float) $item->tax_percent,
                        ])->all();
                    @endphp

                    @if ($quote->isConverted())
                        <div class="flex items-center gap-3 rounded-lg border border-emerald-500/30 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <x-icon name="check-circle" class="size-5" />
                            This quote was converted to a purchase order and can no longer be edited.
                        </div>
                    @endif

                    <x-suppliers.line-items-editor :products="$products" :initial-items="$initialItems" :currency="$quote->currency" />

                    <x-textarea name="notes" label="Notes" rows="3" :disabled="$quote->isConverted()">{{ old('notes', $quote->notes) }}</x-textarea>

                    @if (! $quote->isConverted())
                        <div class="flex justify-end gap-3 border-t border-line pt-4">
                            <x-button type="submit" icon="save">Save changes</x-button>
                        </div>
                    @endif
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Summary">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Subtotal</dt><dd class="text-ink">{{ money($quote->subtotal, $quote->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Tax</dt><dd class="text-ink">{{ money($quote->tax_amount, $quote->currency) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt class="text-ink">Total</dt><dd class="text-ink">{{ money($quote->total, $quote->currency) }}</dd></div>
                </dl>
                <div class="mt-4">
                    <x-suppliers.status-badge :status="$quote->status" />
                    @if ($quote->order)
                        <p class="mt-2 text-xs text-ink-faint">Converted to <a href="{{ route('suppliers.purchase_orders.edit', $quote->order) }}" class="text-primary hover:underline">{{ $quote->order->number }}</a></p>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
