<x-app-layout :pageTitle="'New invoice'">
    <x-slot name="header">
        <x-page-header title="New invoice" description="Create an invoice for a customer." icon="invoice">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Invoice" number="Draft" currency="{{ old('currency', $fromOrder?->currency ?? settings('company.currency', 'USD')) }}" />
                    <x-button href="{{ route('sales.invoices.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Invoice details">
            <form method="POST" action="{{ route('sales.invoices.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                    <x-select name="customer_id" label="Customer" required @change="$event.target.value && syncCurrency($event.target)">
                        <option value="">— Select customer —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" data-currency="{{ $customer->currency ?? '' }}" @selected(old('customer_id', $fromOrder?->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    @if ($fromOrder)
                        <input type="hidden" name="order_id" value="{{ $fromOrder->id }}">
                        <div class="flex items-end">
                            <a href="{{ route('sales.orders.edit', $fromOrder) }}" class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm text-ink-soft hover:border-primary/40">
                                <x-icon name="orders" class="size-4" />
                                Billing {{ $fromOrder->number }}
                            </a>
                        </div>
                    @else
                        <x-select name="order_id" label="Related order">
                            <option value="">— None —</option>
                            @foreach (\App\Models\SalesOrder::query()->whereIn('status', ['confirmed', 'packed', 'shipped', 'delivered'])->orderByDesc('issue_date')->limit(50)->get() as $order)
                                <option value="{{ $order->id }}" @selected(old('order_id') == $order->id)>{{ $order->number }} · {{ $order->customer?->company_name }}</option>
                            @endforeach
                        </x-select>
                    @endif
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\SalesInvoice::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'sent') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" />
                    <x-input name="due_date" label="Due date" type="date" value="{{ old('due_date') }}" />
                    <x-select name="currency" label="Currency" x-ref="currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', $fromOrder?->currency ?? settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                @php
                    $initialItems = $fromOrder ? $fromOrder->items->map(fn ($item) => [
                        'product_id' => (string) ($item->product_id ?? ''),
                        'description' => $item->description,
                        'qty' => (float) $item->qty,
                        'unit_price' => (float) $item->unit_price,
                        'discount_percent' => (float) $item->discount_percent,
                        'tax_percent' => (float) $item->tax_percent,
                    ])->all() : [];
                @endphp

                <x-sales.line-items-editor :products="$products" :initial-items="$initialItems" :currency="old('currency', $fromOrder?->currency ?? settings('company.currency', 'USD'))" :max-discount="$maxDiscount" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.invoices.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create invoice</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
