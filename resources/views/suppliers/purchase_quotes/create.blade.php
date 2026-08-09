<x-app-layout :pageTitle="'New purchase quote'">
    <x-slot name="header">
        <x-page-header title="New purchase quote" description="Request pricing from a supplier." icon="document">
            <x-slot name="actions">
                <x-button href="{{ route('suppliers.purchase_quotes.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Quote details">
            <form method="POST" action="{{ route('suppliers.purchase_quotes.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-select name="supplier_id" label="Supplier" required>
                        <option value="">— Select supplier —</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\PurchaseQuote::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'draft') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="currency" label="Currency" value="{{ old('currency', 'USD') }}" placeholder="USD, EUR…" />
                    <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" />
                    <x-input name="valid_until" label="Valid until" type="date" value="{{ old('valid_until') }}" />
                </div>

                <x-suppliers.line-items-editor :products="$products" :initial-items="[]" :currency="old('currency', 'USD')" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('suppliers.purchase_quotes.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create quote</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
