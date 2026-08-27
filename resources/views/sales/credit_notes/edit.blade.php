<x-app-layout :pageTitle="'Credit note '.$creditNote->number">
    <x-slot name="header">
        <x-page-header title="Credit note {{ $creditNote->number }}" description="{{ $creditNote->customer?->company_name }}" icon="credit">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Credit Note" number="{{ $creditNote->number }}" customerName="{{ $creditNote->customer?->company_name }}" issueDate="{{ $creditNote->issue_date }}" currency="{{ $creditNote->currency }}" notes="{{ $creditNote->notes }}" />
                    <x-button href="{{ route('sales.credit_notes.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Credit note details">
                <form method="POST" action="{{ route('sales.credit_notes.update', $creditNote) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <x-select name="customer_id" label="Customer" required>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('customer_id', $creditNote->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="invoice_id" label="Against invoice">
                            <option value="">— None —</option>
                            @foreach (\App\Models\SalesInvoice::query()->with('customer')->orderByDesc('issue_date')->limit(100)->get() as $inv)
                                <option value="{{ $inv->id }}" @selected(old('invoice_id', $creditNote->invoice_id) == $inv->id)>{{ $inv->number }} · {{ $inv->customer?->company_name }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', $creditNote->issue_date?->format('Y-m-d')) }}" />
                        <x-input name="reason" label="Reason" value="{{ old('reason', $creditNote->reason) }}" />
                        <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', 'currency', $creditNote->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    </div>

                    @php
                        $initialItems = $creditNote->items->map(fn ($item) => [
                            'product_id' => (string) ($item->product_id ?? ''),
                            'description' => $item->description,
                            'qty' => (float) $item->qty,
                            'unit_price' => (float) $item->unit_price,
                            'discount_percent' => (float) $item->discount_percent,
                            'tax_percent' => (float) $item->tax_percent,
                        ])->all();
                    @endphp

                    <x-sales.line-items-editor :products="$products" :initial-items="$initialItems" :currency="$creditNote->currency" :max-discount="$maxDiscount" />

                    <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $creditNote->notes) }}</x-textarea>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button type="submit" icon="save">Save changes</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Summary">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Subtotal</dt><dd class="text-ink">{{ money($creditNote->subtotal, $creditNote->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Tax</dt><dd class="text-ink">{{ money($creditNote->tax_amount, $creditNote->currency) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt class="text-ink">Total</dt><dd class="text-ink">{{ money($creditNote->total, $creditNote->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Applied</dt><dd class="font-medium text-ink">{{ money($creditNote->applied_amount, $creditNote->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Remaining</dt><dd class="font-medium text-ink">{{ money($creditNote->remaining(), $creditNote->currency) }}</dd></div>
                </dl>
                <div class="mt-4">
                    <x-badge color="success" dot>Issued</x-badge>
                    @if ($creditNote->invoice)
                        <p class="mt-2 text-xs text-ink-faint">Against invoice <a href="{{ route('sales.invoices.edit', $creditNote->invoice) }}" class="text-primary hover:underline">{{ $creditNote->invoice->number }}</a></p>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
