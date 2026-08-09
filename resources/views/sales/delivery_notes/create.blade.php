<x-app-layout :pageTitle="'New delivery note'">
    <x-slot name="header">
        <x-page-header title="New delivery note" description="Record goods being delivered." icon="truck">
            <x-slot name="actions">
                <x-button href="{{ route('sales.delivery_notes.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Delivery note details">
            <form method="POST" action="{{ route('sales.delivery_notes.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-select name="customer_id" label="Customer" required>
                        <option value="">— Select customer —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(old('customer_id', $fromOrder?->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    @if ($fromOrder)
                        <input type="hidden" name="order_id" value="{{ $fromOrder->id }}">
                        <div class="flex items-end">
                            <a href="{{ route('sales.orders.edit', $fromOrder) }}" class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm text-ink-soft hover:border-primary/40">
                                <x-icon name="orders" class="size-4" />
                                Fulfilling {{ $fromOrder->number }}
                            </a>
                        </div>
                    @else
                        <x-select name="order_id" label="Related order">
                            <option value="">— None —</option>
                            @foreach (\App\Models\SalesOrder::query()->whereIn('status', ['confirmed', 'packed', 'shipped'])->orderByDesc('issue_date')->limit(50)->get() as $order)
                                <option value="{{ $order->id }}" @selected(old('order_id') == $order->id)>{{ $order->number }} · {{ $order->customer?->company_name }}</option>
                            @endforeach
                        </x-select>
                    @endif
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\SalesDeliveryNote::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'pending') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" />
                    <x-input name="carrier" label="Carrier" value="{{ old('carrier') }}" placeholder="e.g. DHL, FedEx…" />
                    <x-input name="tracking_number" label="Tracking number" value="{{ old('tracking_number') }}" />
                </div>

                <x-input name="shipping_address" label="Shipping address" value="{{ old('shipping_address', $fromOrder?->shipping_address) }}" />

                @php
                    $initialItems = $fromOrder ? $fromOrder->items->map(fn ($item) => [
                        'product_id' => (string) ($item->product_id ?? ''),
                        'description' => $item->description,
                        'qty' => (float) $item->qty,
                    ])->all() : [];
                @endphp

                <x-sales.delivery-items-editor :products="$products" :initial-items="$initialItems" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.delivery_notes.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create delivery note</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
