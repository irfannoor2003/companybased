<x-app-layout :pageTitle="'New supplier payment'">
    <x-slot name="header">
        <x-page-header title="New supplier payment" description="Record a payment made to a supplier." icon="money">
            <x-slot name="actions">
                <x-button href="{{ route('suppliers.supplier_payments.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <x-card title="Payment details">
             <form method="POST" action="{{ route('suppliers.supplier_payments.store') }}" class="space-y-5" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                 @csrf

                 <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                     <x-select name="supplier_id" label="Supplier" required @change="$event.target.value && syncCurrency($event.target)">
                         <option value="">— Select supplier —</option>
                         @foreach ($suppliers as $supplier)
                             <option value="{{ $supplier->id }}" data-currency="{{ $supplier->currency ?? '' }}" @selected(old('supplier_id', $fromInvoice?->supplier_id) == $supplier->id)>{{ $supplier->company_name }}</option>
                         @endforeach
                     </x-select>
                    <x-select name="invoice_id" label="Against invoice">
                        <option value="">— None —</option>
                        @foreach ($invoices as $inv)
                            <option value="{{ $inv->id }}" @selected(old('invoice_id', $fromInvoice?->id) == $inv->id)>{{ $inv->number }} · {{ $inv->supplier?->company_name }} · balance {{ money($inv->balance(), $inv->currency) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" :value="old('amount', $fromInvoice?->balance())" required />
                    <x-input name="payment_date" label="Payment date" type="date" value="{{ old('payment_date', now()->toDateString()) }}" required />
                    <x-select name="method" label="Method" required>
                        @foreach (\App\Models\SupplierPayment::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('method', 'bank_transfer') === $method)>{{ ucwords(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="reference" label="Reference" value="{{ old('reference') }}" placeholder="e.g. bank ref, cheque no." />
                    <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('suppliers.supplier_payments.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Record payment</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
