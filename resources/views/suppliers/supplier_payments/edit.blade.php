<x-app-layout :pageTitle="'Edit payment '.$payment->number">
    <x-slot name="header">
        <x-page-header title="Payment {{ $payment->number }}" description="{{ $payment->supplier?->company_name }}" icon="money">
            <x-slot name="actions">
                <x-button href="{{ route('suppliers.supplier_payments.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-4xl">
        <x-card title="Payment details">
            <form method="POST" action="{{ route('suppliers.supplier_payments.update', $payment) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-select name="supplier_id" label="Supplier" required>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $payment->supplier_id) == $supplier->id)>{{ $supplier->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="invoice_id" label="Against invoice">
                        <option value="">— None —</option>
                        @foreach ($invoices as $inv)
                            <option value="{{ $inv->id }}" @selected(old('invoice_id', $payment->invoice_id) == $inv->id)>{{ $inv->number }} · {{ $inv->supplier?->company_name }} · balance {{ money($inv->balance(), $inv->currency) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" value="{{ old('amount', $payment->amount) }}" required />
                    <x-input name="payment_date" label="Payment date" type="date" value="{{ old('payment_date', $payment->payment_date?->format('Y-m-d')) }}" required />
                    <x-select name="method" label="Method" required>
                        @foreach (\App\Models\SupplierPayment::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('method', $payment->method) === $method)>{{ ucwords(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="reference" label="Reference" value="{{ old('reference', $payment->reference) }}" placeholder="e.g. bank ref, cheque no." />
                    <x-input name="currency" label="Currency" value="{{ old('currency', $payment->currency) }}" placeholder="USD, EUR…" />
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $payment->notes) }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('suppliers.supplier_payments.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
