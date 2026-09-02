<x-app-layout :pageTitle="'Purchase order '.$order->number">
    <x-slot name="header">
        <x-page-header title="Purchase order {{ $order->number }}" description="{{ $order->supplier?->company_name }}" icon="orders">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Purchase Order" number="{{ $order->number }}" customerName="{{ $order->supplier?->company_name }}" issueDate="{{ $order->order_date }}" currency="{{ $order->currency }}" notes="{{ $order->notes }}" />
                    @if (auth()->user()->can('suppliers.purchase_invoices.create') && ! in_array($order->status, ['draft', 'cancelled']))
                        <x-button href="{{ route('suppliers.purchase_invoices.create', ['order' => $order->id]) }}" variant="secondary" icon="invoice">Invoice Against Purchase Order</x-button>
                    @endif
                    <x-button href="{{ route('suppliers.purchase_orders.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Order details">
                @php $locked = in_array($order->status, ['received', 'completed']); @endphp
                <form method="POST" action="{{ route('suppliers.purchase_orders.update', $order) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                        <x-select name="supplier_id" label="Supplier" required :disabled="$locked" @change="$event.target.value && syncCurrency($event.target)">
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" data-currency="{{ $supplier->currency ?? '' }}" @selected(old('supplier_id', $order->supplier_id) == $supplier->id)>{{ $supplier->company_name }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="warehouse_id" label="Receive into warehouse" :disabled="$locked" hint="Stock is added here when the order is received.">
                            <option value="">— None (no stock receipt) —</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected(old('warehouse_id', $order->warehouse_id) == $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="status" label="Status" :disabled="$locked">
                            @foreach (\App\Models\PurchaseOrder::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(old('status', $order->status) === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="order_date" label="Order date" type="date" value="{{ old('order_date', $order->order_date?->format('Y-m-d')) }}" :disabled="$locked" />
                        <x-input name="expected_delivery_date" label="Expected delivery" type="date" value="{{ old('expected_delivery_date', $order->expected_delivery_date?->format('Y-m-d')) }}" :disabled="$locked" />
                        <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', 'currency', $order->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    </div>

                    <x-input name="shipping_address" label="Shipping address" value="{{ old('shipping_address', $order->shipping_address) }}" :disabled="$locked" />

                    @php
                        $initialItems = $order->items->map(fn ($item) => [
                            'product_id' => (string) ($item->product_id ?? ''),
                            'description' => $item->description,
                            'qty' => (float) $item->qty,
                            'unit_price' => (float) $item->unit_price,
                            'discount_percent' => (float) $item->discount_percent,
                            'tax_percent' => (float) $item->tax_percent,
                        ])->all();
                    @endphp

                    <x-suppliers.line-items-editor :products="$products" :initial-items="$initialItems" :currency="$order->currency" />

                    <x-textarea name="notes" label="Notes" rows="3" :disabled="$locked">{{ old('notes', $order->notes) }}</x-textarea>

                    @if (! $locked)
                        <div class="flex justify-end gap-3 border-t border-line pt-4">
                            <x-button type="submit" icon="save">Save changes</x-button>
                        </div>
                    @endif
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Summary">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Subtotal</dt><dd class="text-ink">{{ money($order->subtotal, $order->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Tax</dt><dd class="text-ink">{{ money($order->tax_amount, $order->currency) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt class="text-ink">Total</dt><dd class="text-ink">{{ money($order->total, $order->currency) }}</dd></div>
                </dl>
                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <x-suppliers.status-badge :status="$order->status" />
                        @if ($order->status === 'draft' && auth()->user()->can('suppliers.purchase_orders.confirm'))
                            <form method="POST" action="{{ route('suppliers.purchase_orders.confirm', $order) }}">
                                @csrf
                                <x-button type="submit" size="sm" icon="check-circle">Confirm order</x-button>
                            </form>
                        @endif
                    </div>
                    @if ($order->status !== 'draft' && $order->status !== 'cancelled' && auth()->user()->can('suppliers.purchase_orders.update_status'))
                        <form method="POST" action="{{ route('suppliers.purchase_orders.status', $order) }}" class="flex gap-2">
                            @csrf
                            @method('PATCH')
                            <x-select name="status" size="sm" class="flex-1">
                                @foreach (\App\Models\PurchaseOrder::statusOptions() as $status)
                                    <option value="{{ $status }}" @selected($order->status === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </x-select>
                            <x-button type="submit" size="sm" variant="secondary">Update</x-button>
                        </form>
                        <p class="text-xs text-ink-faint">Marking as received adds the ordered stock to the selected warehouse.</p>
                    @endif
                </div>
            </x-card>

            <x-card title="Timeline">
                @forelse ($order->statusEvents->reverse() as $event)
                    <div class="flex gap-3 border-l border-line pb-4 pl-4 last:border-0 last:pb-0">
                        <div class="relative -ml-[21px] mt-1 size-3 shrink-0 rounded-full border-2 border-line bg-surface"></div>
                        <div>
                            <p class="text-sm text-ink">
                                {{ $event->from_status ? ucfirst(str_replace('_', ' ', $event->from_status)).' → ' : '' }}<span class="font-medium">{{ ucfirst(str_replace('_', ' ', $event->to_status)) }}</span>
                            </p>
                            @if ($event->note)
                                <p class="text-xs text-ink-soft">{{ $event->note }}</p>
                            @endif
                            <p class="mt-0.5 text-xs text-ink-faint">{{ $event->created_at->format('M j, H:i') }} · {{ $event->user?->name ?: 'System' }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-ink-faint">No status changes yet.</p>
                @endforelse
            </x-card>

            @if ($order->invoices->isNotEmpty())
                <x-card title="Invoices">
                    @foreach ($order->invoices as $inv)
                        <a href="{{ route('suppliers.purchase_invoices.edit', $inv) }}" class="flex items-center justify-between py-1 text-sm hover:text-primary">
                            <span class="font-medium text-ink">{{ $inv->number }}</span>
                            <x-suppliers.status-badge :status="$inv->status" />
                        </a>
                    @endforeach
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
