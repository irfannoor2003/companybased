<x-app-layout :pageTitle="'Shipment '.$shipment->number">
    <x-slot name="header">
        <x-page-header title="Incoming shipment {{ $shipment->number }}" description="{{ $shipment->supplier?->name ?? $shipment->warehouse?->name ?? 'No supplier' }}" icon="truck">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.incoming_shipments.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                @if (auth()->user()->can('inventory.incoming_shipments.edit') && ! $shipment->isLocked())
                    <x-button href="{{ route('inventory.incoming_shipments.edit', $shipment) }}" icon="edit">Edit shipment</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Shipment details">
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-ink-faint">Supplier</dt>
                        <dd class="font-medium text-ink">{{ $shipment->supplier?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-faint">Destination warehouse</dt>
                        <dd class="font-medium text-ink">{{ $shipment->warehouse?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-faint">Related purchase order</dt>
                        <dd class="font-medium text-ink">{{ $shipment->purchaseOrder?->number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-faint">Expected arrival</dt>
                        <dd class="font-medium text-ink">{{ $shipment->expected_arrival_at?->format('Y-m-d') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-faint">Arrived at</dt>
                        <dd class="font-medium text-ink">{{ $shipment->arrived_at?->format('M j, Y H:i') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-faint">Approved at</dt>
                        <dd class="font-medium text-ink">{{ $shipment->approved_at?->format('M j, Y H:i') ?: '—' }}</dd>
                    </div>
                    <div class="pt-1 col-span-2">
                        <dt class="text-ink-faint">Notes</dt>
                        <dd class="font-medium text-ink">{{ $shipment->notes ?: '—' }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Items">
                @if ($shipment->items->isEmpty())
                    <x-empty-state icon="package" title="No items" description="Add items on the edit page." />
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-right">Expected</th>
                                    <th class="text-right">Received</th>
                                    <th class="text-right">Variance</th>
                                    <th class="text-right">Unit cost</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shipment->items as $line)
                                    @php
                                        $variance = (float) $line->received_quantity - (float) $line->expected_quantity;
                                    @endphp
                                    <tr>
                                        <td class="font-medium text-ink">{{ $line->product?->name }}{{ $line->product?->sku ? ' ('.$line->product->sku.')' : '' }}</td>
                                        <td class="text-right font-mono text-ink-soft">{{ number_format((float) $line->expected_quantity, 3) }}</td>
                                        <td class="text-right font-mono text-ink-soft">{{ number_format((float) $line->received_quantity, 3) }}</td>
                                        <td class="text-right font-mono {{ $variance == 0 ? 'text-ink' : ($variance < 0 ? 'text-rose-600' : 'text-emerald-600') }}">
                                            {{ $variance > 0 ? '+' : '' }}{{ number_format($variance, 3) }}
                                        </td>
                                        <td class="text-right text-ink-soft">{{ $line->unit_cost ? money((float) $line->unit_cost, settings('company.currency')) : '—' }}</td>
                                        <td class="text-ink-soft">{{ $line->notes ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Status">
                <div class="flex items-center justify-between">
                    <x-inventory.status-badge :status="$shipment->status" />
                    <span class="text-xs text-ink-faint">{{ ucfirst(str_replace('_', ' ', $shipment->status)) }}</span>
                </div>

                @if (! $shipment->isLocked() && auth()->user()->can('inventory.incoming_shipments.receive'))
                    <form method="POST" action="{{ route('inventory.incoming_shipments.status', $shipment) }}" class="mt-4 flex items-end gap-2">
                        @csrf
                        @method('PATCH')
                        <x-select name="status" size="sm" class="w-44">
                            @foreach (\App\Models\InventoryIncomingShipment::statusOptions() as $status)
                                @if ($status === 'approved') @continue @endif
                                <option value="{{ $status }}" @selected($shipment->status === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </x-select>
                        <x-button type="submit" size="sm" variant="secondary">Update</x-button>
                    </form>
                    <p class="mt-2 text-xs text-ink-faint">Advance through Pending → In transit → Arrived, then Approve.</p>
                @endif
            </x-card>

            @if ($shipment->status === 'arrived' && ! $shipment->isLocked() && auth()->user()->can('inventory.incoming_shipments.approve'))
                <x-card title="Approve receipt">
                    <p class="text-sm text-ink-faint">
                        Adjust received quantities on the edit page, then approve to post
                        {{ $shipment->items->sum('received_quantity') }} units into
                        {{ $shipment->warehouse?->name ?? 'the warehouse' }}.
                    </p>
                    <form method="POST" action="{{ route('inventory.incoming_shipments.approve', $shipment) }}" class="mt-4">
                        @csrf
                        <x-button type="submit" icon="check">Approve shipment</x-button>
                    </form>
                </x-card>
            @endif

            <x-card title="Summary">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Lines</dt>
                        <dd class="font-medium text-ink">{{ $shipment->items->count() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Expected total</dt>
                        <dd class="font-medium text-ink">{{ number_format((float) $shipment->items->sum('expected_quantity'), 3) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Received total</dt>
                        <dd class="font-medium text-ink">{{ number_format((float) $shipment->items->sum('received_quantity'), 3) }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
