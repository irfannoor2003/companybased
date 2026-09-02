<x-app-layout :pageTitle="'Production order '.$order->number">
    <x-slot name="header">
        <x-page-header title="Production order {{ $order->number }}" description="{{ $order->item?->product?->name }} · {{ $order->warehouse?->name }}" icon="zap">
            <x-slot name="actions">
                @if (auth()->user()->can('inventory.production_orders.edit') && ! in_array($order->status, ['completed', 'cancelled']))
                    <x-button href="{{ route('inventory.production_orders.edit', $order) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
                @if (auth()->user()->can('suppliers.purchase_invoices.create') && ! in_array($order->status, ['completed', 'cancelled']))
                    <x-button href="{{ route('suppliers.purchase_invoices.create', ['production_order' => $order->id]) }}" variant="secondary" icon="invoice">Convert to purchase invoice</x-button>
                @endif
                <x-button href="{{ route('inventory.production_orders.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card :padding="false">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="text-sm font-semibold text-ink">Components</h2>
                    @if ($order->billOfMaterial)
                        <p class="mt-1 text-xs text-ink-faint">Derived from {{ $order->billOfMaterial->name }}.</p>
                    @endif
                </div>

                @if ($order->items->isEmpty())
                    <x-empty-state icon="zap" title="No components" description="No components are linked to this production order." />
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Component</th>
                                    <th>SKU</th>
                                    <th class="text-right">Required</th>
                                    <th class="text-right">Used</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($order->items as $line)
                                    <tr>
                                        <td class="font-medium text-ink">{{ $line->componentItem?->product?->name ?? '—' }}</td>
                                        <td class="text-ink-soft">{{ $line->componentItem?->product?->sku ?? '—' }}</td>
                                        <td class="text-right text-ink">{{ $line->quantity_required }}</td>
                                        <td class="text-right text-ink">{{ $line->quantity_used }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Details">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Item to produce</dt>
                        <dd class="font-medium text-ink">{{ $order->item?->product?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Quantity</dt>
                        <dd class="font-medium text-ink">{{ $order->quantity }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Warehouse</dt>
                        <dd class="font-medium text-ink">{{ $order->warehouse?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Start date</dt>
                        <dd class="font-medium text-ink">{{ $order->scheduled_start_date?->format('Y-m-d') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">End date</dt>
                        <dd class="font-medium text-ink">{{ $order->scheduled_end_date?->format('Y-m-d') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Status</dt>
                        <dd><x-inventory.status-badge :status="$order->status" /></dd>
                    </div>
                </dl>
            </x-card>

            @if ($order->note)
                <x-card title="Note">
                    <p class="text-sm text-ink-soft">{{ $order->note }}</p>
                </x-card>
            @endif

            <x-card title="Summary">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Components</dt>
                        <dd class="font-medium text-ink">{{ $order->items->count() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Total required</dt>
                        <dd class="font-medium text-ink">{{ $order->items->sum('quantity_required') }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
