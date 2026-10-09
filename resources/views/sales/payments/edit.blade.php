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
             <form method="POST" action="{{ route('sales.sales_payments.update', $payment) }}" class="space-y-5" x-data="currencyFromEntity() paymentOverpayment()" x-init="initCurrencySelect()">
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
                     @include('documents.partials.reference-number', [
                         'label' => 'Manual receipt number',
                         'example' => 'RC-OS-4471',
                         'value' => $payment->reference_number,
                     ])
                     <x-select name="customer_id" label="Customer" required @change="$event.target.value && syncCurrency($event.target)">
                         @foreach ($customers as $customer)
                             <option value="{{ $customer->id }}" data-currency="{{ $customer->currency ?? '' }}" @selected(old('customer_id', $payment->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                         @endforeach
                     </x-select>
                     <x-select name="invoice_id" label="Against invoice" x-on:change="confirmed = false">
                         <option value="">— None —</option>
                         @foreach ($invoices as $inv)
                             <option value="{{ $inv->id }}" data-balance="{{ $inv->balance() }}" @selected(old('invoice_id', $payment->invoice_id) == $inv->id)>{{ $inv->number }} · {{ $inv->customer?->company_name }} · balance {{ money($inv->balance(), $inv->currency) }}</option>
                         @endforeach
                     </x-select>
                     <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" value="{{ old('amount', $payment->amount) }}" required x-on:input="confirmed = false" />
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

                 <input type="hidden" name="overpayment_confirmed" x-bind:value="confirmed ? 1 : 0">

                 <div x-show="isOverpayment()" x-cloak class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                     <p class="font-semibold">This is more than the invoice is owed.</p>
                     <p class="mt-1">
                         <span x-text="format(applied())"></span> will settle the invoice and
                         <span x-text="format(surplus())"></span> will be held as credit on the
                         customer's account.
                     </p>
                 </div>

                 <div class="flex justify-end gap-3 border-t border-line pt-4">
                     <x-button href="{{ route('sales.sales_payments.index') }}" variant="ghost">Cancel</x-button>
                     <x-button type="submit" icon="save" x-on:click="confirm($event)">Save changes</x-button>
                 </div>
             </form>
        </x-card>
    </div>
</x-app-layout>
