<x-app-layout :pageTitle="'New purchase order'">
    <x-slot name="header">
        <x-page-header title="New purchase order" description="Create a purchase order for a supplier." icon="orders">
            <x-slot name="actions">
                <x-button href="{{ route('suppliers.purchase_orders.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Order details">
            <form method="POST" action="{{ route('suppliers.purchase_orders.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                    <x-select name="supplier_id" label="Supplier" required @change="$event.target.value && syncCurrency($event.target)">
                        <option value="">— Select supplier —</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" data-currency="{{ $supplier->currency ?? '' }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="warehouse_id" label="Receive into warehouse" hint="Stock is added here when the order is received.">
                        <option value="">— None (no stock receipt) —</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\PurchaseOrder::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'draft') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="order_date" label="Order date" type="date" value="{{ old('order_date', now()->toDateString()) }}" />
                    <x-input name="expected_delivery_date" label="Expected delivery" type="date" value="{{ old('expected_delivery_date') }}" />
                </div>

                <x-input name="shipping_address" label="Shipping address" value="{{ old('shipping_address') }}" placeholder="Supplier address by default…" />

                <x-suppliers.line-items-editor :products="$products" :initial-items="[]" :currency="old('currency', settings('company.currency', 'USD'))" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('suppliers.purchase_orders.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create purchase order</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
