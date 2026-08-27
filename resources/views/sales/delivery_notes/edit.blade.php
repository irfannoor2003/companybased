<x-app-layout :pageTitle="'Delivery note '.$deliveryNote->number">
    <x-slot name="header">
        <x-page-header title="Delivery note {{ $deliveryNote->number }}" description="{{ $deliveryNote->customer?->company_name }}" icon="truck">
            <x-slot name="actions">
                <div class="flex items-center gap-2">
                    <x-document-preview type="Delivery Note" number="{{ $deliveryNote->number }}" customerName="{{ $deliveryNote->customer?->company_name }}" issueDate="{{ $deliveryNote->issue_date }}" />
                    <x-button href="{{ route('sales.delivery_notes.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Delivery note details">
                <form method="POST" action="{{ route('sales.delivery_notes.update', $deliveryNote) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <x-select name="customer_id" label="Customer" required>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" @selected(old('customer_id', $deliveryNote->customer_id) == $customer->id)>{{ $customer->company_name }}</option>
                            @endforeach
                        </x-select>
                        <x-select name="status" label="Status">
                            @foreach (\App\Models\SalesDeliveryNote::statusOptions() as $status)
                                <option value="{{ $status }}" @selected(old('status', $deliveryNote->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', $deliveryNote->issue_date?->format('Y-m-d')) }}" />
                        <x-input name="carrier" label="Carrier" value="{{ old('carrier', $deliveryNote->carrier) }}" />
                        <x-input name="tracking_number" label="Tracking number" value="{{ old('tracking_number', $deliveryNote->tracking_number) }}" />
                    </div>

                    <x-input name="shipping_address" label="Shipping address" value="{{ old('shipping_address', $deliveryNote->shipping_address) }}" />

                    @php
                        $initialItems = $deliveryNote->items->map(fn ($item) => [
                            'product_id' => (string) ($item->product_id ?? ''),
                            'description' => $item->description,
                            'qty' => (float) $item->qty,
                        ])->all();
                    @endphp

                    <x-sales.delivery-items-editor :products="$products" :initial-items="$initialItems" />

                    <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $deliveryNote->notes) }}</x-textarea>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button type="submit" icon="save">Save changes</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Status">
                <div class="flex items-center justify-between">
                    <x-sales.status-badge :status="$deliveryNote->status" />
                    @if (auth()->user()->can('sales.delivery_notes.update_status'))
                        <form method="POST" action="{{ route('sales.delivery_notes.status', $deliveryNote) }}" class="flex gap-2">
                            @csrf
                            @method('PATCH')
                            <x-select name="status" size="sm" class="w-32">
                                @foreach (\App\Models\SalesDeliveryNote::statusOptions() as $status)
                                    <option value="{{ $status }}" @selected($deliveryNote->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </x-select>
                            <x-button type="submit" size="sm" variant="secondary">Update</x-button>
                        </form>
                    @endif
                </div>
                @if ($deliveryNote->order)
                    <p class="mt-3 text-xs text-ink-faint">For order <a href="{{ route('sales.orders.edit', $deliveryNote->order) }}" class="text-primary hover:underline">{{ $deliveryNote->order->number }}</a></p>
                @endif
            </x-card>

            <x-card title="Timeline">
                @forelse ($deliveryNote->statusEvents->reverse() as $event)
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
        </div>
    </div>
</x-app-layout>
