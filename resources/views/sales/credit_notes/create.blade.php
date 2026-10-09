<x-app-layout :pageTitle="'New credit note'">
    <x-slot name="header">
        <x-page-header title="New credit note" description="Issue a credit note to a customer." icon="credit">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Credit Note" number="Draft" currency="{{ old('currency', settings('company.currency', 'USD')) }}" />
                    <x-button href="{{ route('sales.credit_notes.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Credit note details">
            <form method="POST" action="{{ route('sales.credit_notes.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-select name="customer_id" label="Customer" required>
                        <option value="">â€” Select customer â€”</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(old('customer_id', $fromInvoice?->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="invoice_id" label="Against invoice">
                        <option value="">â€” None â€”</option>
                        @foreach (\App\Models\SalesInvoice::query()->with('customer')->orderByDesc('issue_date')->limit(100)->get() as $inv)
                            <option value="{{ $inv->id }}" @selected(old('invoice_id', $fromInvoice?->id) == $inv->id)>{{ $inv->number }} Â· {{ $inv->customer?->company_name }} Â· {{ $inv->balance() > 0 ? 'balance '.money($inv->balance(), $inv->currency) : 'paid' }}</option>
                        @endforeach
                    </x-select>
                    @include('documents.partials.reference-number', ['example' => 'CN-2026-0001'])
                    <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" />
                    <x-input name="reason" label="Reason" value="{{ old('reason') }}" placeholder="e.g. Wrong goods, returnâ€¦" />
                    <x-select name="currency" label="Currency">
                        <option value="">â€” Default â€”</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                @php
                    $initialItems = $fromInvoice ? $fromInvoice->items->map(fn ($item) => [
                        'product_id' => (string) ($item->product_id ?? ''),
                        'description' => $item->description,
                        'qty' => (float) $item->qty,
                        'unit_price' => (float) $item->unit_price,
                        'discount_percent' => (float) $item->discount_percent,
                        'tax_percent' => (float) $item->tax_percent,
                    ])->all() : [];
                @endphp

                <x-sales.line-items-editor :products="$products" :initial-items="$initialItems" :currency="old('currency', settings('company.currency', 'USD'))" :max-discount="$maxDiscount" :tax-hint="$taxHint" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.credit_notes.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create credit note</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
