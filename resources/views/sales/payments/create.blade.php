<x-app-layout :pageTitle="'New payment in'">
    <x-slot name="header">
        <x-page-header title="New payment in" description="Record a payment received from a customer." icon="money">
            <x-slot name="actions">
                <x-button href="{{ route('sales.sales_payments.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <x-card title="Payment details">
             <form method="POST" action="{{ route('sales.sales_payments.store') }}" class="space-y-5" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                 @csrf

                 <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                     <x-select name="customer_id" label="Customer" required @change="$event.target.value && syncCurrency($event.target)">
                         <option value="">— Select customer —</option>
                         @foreach ($customers as $customer)
                             <option value="{{ $customer->id }}" data-currency="{{ $customer->currency ?? '' }}" @selected(old('customer_id', $fromInvoice?->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                         @endforeach
                     </x-select>
                     <x-select name="invoice_id" label="Against invoice">
                         <option value="">— None —</option>
                         @foreach ($invoices as $inv)
                             <option value="{{ $inv->id }}" @selected(old('invoice_id', $fromInvoice?->id) == $inv->id)>{{ $inv->number }} · {{ $inv->customer?->company_name }} · balance {{ money($inv->balance(), $inv->currency) }}</option>
                         @endforeach
                     </x-select>
                     <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" :value="old('amount', $fromInvoice?->balance())" required />
                     <x-input name="payment_date" label="Payment date" type="date" value="{{ old('payment_date', now()->toDateString()) }}" required />
                     <x-select name="method" label="Method" required>
                         @foreach (\App\Models\SalesPayment::methodOptions() as $method)
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
                     <x-button href="{{ route('sales.sales_payments.index') }}" variant="ghost">Cancel</x-button>
                     <x-button type="submit" icon="save">Record payment</x-button>
                 </div>
             </form>
        </x-card>
    </div>
</x-app-layout>
