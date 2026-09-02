<x-app-layout :pageTitle="'Edit payment '.$payment->number">
    <x-slot name="header">
        <x-page-header title="Payment {{ $payment->number }}" description="{{ $payment->customer?->company_name }}" icon="money">
            <x-slot name="actions">
                <x-button :href="route('sales.sales_payments.pdf', $payment)" variant="secondary" icon="download" target="_blank" rel="noopener">Export PDF</x-button>
                <x-button href="{{ route('sales.sales_payments.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <x-card title="Payment details">
             <form method="POST" action="{{ route('sales.sales_payments.update', $payment) }}" class="space-y-5" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                 @csrf
                 @method('PUT')

                 <div class="rounded-lg border border-line bg-surface-muted/50 px-4 py-3">
                     <p class="text-xs font-semibold uppercase text-ink-faint">Customer</p>
                     <p class="mt-0.5 text-sm font-medium text-ink">{{ $payment->customer?->company_name ?: '—' }}</p>
                     @if ($payment->customer?->short_code)
                         <p class="text-xs text-ink-faint">Code: {{ $payment->customer->short_code }}</p>
                     @endif
                 </div>

                 <div class="rounded-lg border border-line bg-surface-muted/50 px-4 py-3">
                     <p class="text-xs font-semibold uppercase text-ink-faint">Against invoice</p>
                     <p class="mt-0.5 text-sm font-medium text-ink">{{ $payment->invoice?->number ?: '—' }}</p>
                 </div>

                 <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                     <x-select name="customer_id" label="Customer" required @change="$event.target.value && syncCurrency($event.target)">
                         @foreach ($customers as $customer)
                             <option value="{{ $customer->id }}" data-currency="{{ $customer->currency ?? '' }}" @selected(old('customer_id', $payment->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                         @endforeach
                     </x-select>
                     <x-select name="invoice_id" label="Against invoice">
                         <option value="">— None —</option>
                         @foreach ($invoices as $inv)
                             <option value="{{ $inv->id }}" @selected(old('invoice_id', $payment->invoice_id) == $inv->id)>{{ $inv->number }} · {{ $inv->customer?->company_name }} · balance {{ money($inv->balance(), $inv->currency) }}</option>
                         @endforeach
                     </x-select>
                     <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" value="{{ old('amount', $payment->amount) }}" required />
                     <x-input name="payment_date" label="Payment date" type="date" value="{{ old('payment_date', $payment->payment_date?->format('Y-m-d')) }}" required />
                     <x-select name="method" label="Method" required>
                         @foreach (\App\Models\SalesPayment::methodOptions() as $method)
                             <option value="{{ $method }}" @selected(old('method', $payment->method) === $method)>{{ ucwords(str_replace('_', ' ', $method)) }}</option>
                         @endforeach
                     </x-select>
                     <x-input name="reference" label="Reference" value="{{ old('reference', $payment->reference) }}" placeholder="e.g. bank ref, cheque no." />
                     @include('partials.payment-bank-account', ['pbSelected' => old('bank_account_id', $payment->bank_account_id)])
                     <x-select name="currency" label="Currency">
                         <option value="">— Default —</option>
                         @foreach (currency_options() as $code => $label)
                             <option value="{{ $code }}" @selected(old('currency', $payment->currency) === $code)>{{ $label }}</option>
                         @endforeach
                     </x-select>
                 </div>

                 <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $payment->notes) }}</x-textarea>

                 <div class="flex justify-end gap-3 border-t border-line pt-4">
                     <x-button href="{{ route('sales.sales_payments.index') }}" variant="ghost">Cancel</x-button>
                     <x-button type="submit" icon="save">Save changes</x-button>
                 </div>
             </form>
        </x-card>
    </div>
</x-app-layout>
