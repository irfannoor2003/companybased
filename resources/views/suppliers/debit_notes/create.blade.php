<x-app-layout :pageTitle="'New debit note'">
    <x-slot name="header">
        <x-page-header title="New debit note" description="Issue a debit note to a supplier." icon="credit">
            <x-slot name="actions">
                <x-button href="{{ route('suppliers.debit_notes.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Debit note details">
            <form method="POST" action="{{ route('suppliers.debit_notes.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-select name="supplier_id" label="Supplier" required>
                        <option value="">— Select supplier —</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $fromInvoice?->supplier_id) == $supplier->id)>{{ $supplier->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="invoice_id" label="Against invoice">
                        <option value="">— None —</option>
                        @foreach (\App\Models\PurchaseInvoice::query()->with('supplier')->orderByDesc('issue_date')->limit(100)->get() as $inv)
                            <option value="{{ $inv->id }}" @selected(old('invoice_id', $fromInvoice?->id) == $inv->id)>{{ $inv->number }} · {{ $inv->supplier?->company_name }} · {{ $inv->balance() > 0 ? 'balance '.money($inv->balance(), $inv->currency) : 'paid' }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" />
                    <x-input name="reason" label="Reason" value="{{ old('reason') }}" placeholder="e.g. Damaged goods, overcharge…" />
                    <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', 'currency', settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
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

                <x-suppliers.line-items-editor :products="$products" :initial-items="$initialItems" :currency="old('currency', settings('company.currency', 'USD'))" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('suppliers.debit_notes.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create debit note</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
