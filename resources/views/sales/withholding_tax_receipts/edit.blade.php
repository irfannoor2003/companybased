<x-app-layout :pageTitle="'Edit withholding tax receipt'">
    <x-slot name="header">
        <x-page-header title="Withholding tax receipt {{ $withholdingTaxReceipt->number }}" description="{{ $withholdingTaxReceipt->customer?->company_name }}" icon="tax">
            <x-slot name="actions">
                <x-button :href="route('sales.withholding_tax_receipts.pdf', $withholdingTaxReceipt)" variant="secondary" icon="download" target="_blank" rel="noopener">Export PDF</x-button>
                <x-button href="{{ route('sales.withholding_tax_receipts.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <x-card title="Receipt details">
            <form method="POST" action="{{ route('sales.withholding_tax_receipts.update', $withholdingTaxReceipt) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-select name="customer_id" label="Customer" required>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(old('customer_id', $withholdingTaxReceipt->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="invoice_id" label="Invoice">
                        <option value="">— None —</option>
                        @foreach (\App\Models\SalesInvoice::query()->with('customer')->orderByDesc('issue_date')->limit(100)->get() as $inv)
                            <option value="{{ $inv->id }}" @selected(old('invoice_id', $withholdingTaxReceipt->invoice_id) == $inv->id)>{{ $inv->number }} · {{ $inv->customer?->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="receipt_date" label="Receipt date" type="date" value="{{ old('receipt_date', $withholdingTaxReceipt->receipt_date?->format('Y-m-d')) }}" />
                    <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', 'currency', $withholdingTaxReceipt->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="amount" label="Payment amount" type="number" step="0.01" min="0" value="{{ old('amount', $withholdingTaxReceipt->amount) }}" />
                    <x-input name="tax_rate_percent" label="Withholding rate %" type="number" step="0.01" min="0" max="100" value="{{ old('tax_rate_percent', $withholdingTaxReceipt->tax_rate_percent) }}" hint="Tax amount is calculated automatically." />
                </div>

                <div class="rounded-lg border border-line bg-surface-muted/40 px-4 py-3 text-sm">
                    <span class="text-ink-faint">Tax withheld: </span>
                    <span class="font-medium text-ink">{{ money($withholdingTaxReceipt->tax_amount, $withholdingTaxReceipt->currency) }}</span>
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $withholdingTaxReceipt->notes) }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.withholding_tax_receipts.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
