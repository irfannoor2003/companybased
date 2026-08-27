<x-app-layout :pageTitle="'Order '.$order->number">
    <x-slot name="header">
        <x-page-header title="Order {{ $order->number }}" description="{{ $order->customer?->company_name }}" icon="orders">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Order" number="{{ $order->number }}" customerName="{{ $order->customer?->company_name }}" issueDate="{{ $order->issue_date }}" currency="{{ $order->currency }}" notes="{{ $order->notes }}" />
                    @if (auth()->user()->can('sales.invoices.create'))
                        <x-button href="{{ route('sales.invoices.create', ['order' => $order->id]) }}" variant="secondary" icon="invoice">Create invoice</x-button>
                    @endif
                    @if (auth()->user()->can('sales.delivery_notes.create'))
                        <x-button href="{{ route('sales.delivery_notes.create', ['order' => $order->id]) }}" variant="secondary" icon="truck">Create delivery note</x-button>
                    @endif
                    <x-button href="{{ route('sales.orders.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Order details">
                <form method="POST" action="{{ route('sales.orders.update', $order) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                        <x-select name="customer_id" label="Customer" required @change="$event.target.value && syncCurrency($event.target)">
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" data-currency="{{ $customer->currency ?? '' }}" @selected(old('customer_id', $order->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="status" label="Status">
                            @foreach (\App\Models\SalesOrder::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(old('status', $order->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', 'currency', $order->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                        <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', $order->issue_date?->format('Y-m-d')) }}" />
                        <x-input name="expected_delivery_date" label="Expected delivery" type="date" value="{{ old('expected_delivery_date', $order->expected_delivery_date?->format('Y-m-d')) }}" />
                    </div>

                    <x-input name="shipping_address" label="Shipping address" value="{{ old('shipping_address', $order->shipping_address) }}" />

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

                    <x-sales.line-items-editor :products="$products" :initial-items="$initialItems" :currency="$order->currency" :max-discount="$maxDiscount" />

                    <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $order->notes) }}</x-textarea>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button type="submit" icon="save">Save changes</x-button>
                    </div>
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
                        <x-sales.status-badge :status="$order->status" />
                        @if ($order->status === 'draft' && auth()->user()->can('sales.orders.confirm'))
                            <form method="POST" action="{{ route('sales.orders.confirm', $order) }}">
                                @csrf
                                <x-button type="submit" size="sm" icon="check-circle">Confirm order</x-button>
                            </form>
                        @endif
                    </div>
                    @if ($order->status !== 'draft' && auth()->user()->can('sales.orders.update_status'))
                        <form method="POST" action="{{ route('sales.orders.status', $order) }}" class="flex gap-2">
                            @csrf
                            @method('PATCH')
                            <x-select name="status" size="sm" class="flex-1">
                                @foreach (\App\Models\SalesOrder::statusOptions() as $status)
                                    <option value="{{ $status }}" @selected($order->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </x-select>
                            <x-button type="submit" size="sm" variant="secondary">Update</x-button>
                        </form>
                    @endif
                </div>
            </x-card>

            <x-card title="Timeline">
                @forelse ($order->statusEvents->reverse() as $event)
                    <div class="flex gap-3 border-l border-line pb-4 pl-4 last:border-0 last:pb-0">
                        <div class="relative -ml-[21px] mt-1 size-3 shrink-0 rounded-full border-2 border-line bg-surface"></div>
                        <div>
                            <p class="text-sm text-ink">
                                {{ $event->from_status ? ucfirst($event->from_status).' → ' : '' }}<span class="font-medium">{{ ucfirst($event->to_status) }}</span>
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

            @if ($order->deliveryNotes->isNotEmpty())
                <x-card title="Delivery notes">
                    @foreach ($order->deliveryNotes as $dn)
                        <a href="{{ route('sales.delivery_notes.edit', $dn) }}" class="flex items-center justify-between py-1 text-sm hover:text-primary">
                            <span class="font-medium text-ink">{{ $dn->number }}</span>
                            <x-sales.status-badge :status="$dn->status" />
                        </a>
                    @endforeach
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
