<x-app-layout :pageTitle="'New order'">
    <x-slot name="header">
        <x-page-header title="New order" description="Create a sales order." icon="orders">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Order" number="Draft" currency="{{ old('currency', settings('company.currency', 'USD')) }}" />
                    <x-button href="{{ route('sales.orders.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Order details">
            <form method="POST" action="{{ route('sales.orders.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                    <x-select name="customer_id" label="Customer" required @change="$event.target.value && syncCurrency($event.target)">
                        <option value="">— Select customer —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" data-currency="{{ $customer->currency ?? '' }}" @selected(old('customer_id') == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\SalesOrder::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'draft') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" />
                    <x-input name="expected_delivery_date" label="Expected delivery" type="date" value="{{ old('expected_delivery_date') }}" />
                </div>

                <x-input name="shipping_address" label="Shipping address" value="{{ old('shipping_address') }}" placeholder="Customer address by default…" />

                <x-sales.line-items-editor :products="$products" :initial-items="[]" :currency="old('currency', settings('company.currency', 'USD'))" :max-discount="$maxDiscount" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.orders.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create order</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
