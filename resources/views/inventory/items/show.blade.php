<x-app-layout :pageTitle="'Item — '.$item->product?->name">
    <x-slot name="header">
        <x-page-header title="{{ $item->product?->name }}" description="{{ $item->product?->sku ?: 'No SKU' }} · {{ $item->product?->unit }}"
            icon="package">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.items.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
                @if (auth()->user()->can('inventory.items.adjust_stock'))
                    <x-button href="{{ route('inventory.items.adjust', $item) }}" icon="zap">Adjust stock</x-button>
                @endif
                @if (auth()->user()->can('inventory.items.edit'))
                    <x-button href="{{ route('inventory.items.edit', $item) }}" variant="secondary" icon="edit">Edit</x-button>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <x-card title="Stock by warehouse">
                @forelse ($item->stock as $stock)
                    <div class="flex items-center justify-between border-b border-line py-2 last:border-0">
                        <span class="text-sm text-ink-soft">{{ $stock->warehouse?->name }}</span>
                        <span class="text-sm font-medium text-ink">{{ number_format((float) $stock->quantity, 3) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-ink-faint">No stock recorded yet.</p>
                @endforelse
                <div class="mt-3 flex items-center justify-between border-t border-line pt-3">
                    <span class="text-sm font-medium text-ink">Total on hand</span>
                    <span class="text-base font-semibold text-primary">{{ number_format((float) $item->stock->sum('quantity'), 3) }}</span>
                </div>
            </x-card>

            @if (isset($incomingQty) && $incomingQty > 0)
                <x-card title="Incoming">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-ink-faint">Expected on {{ isset($incomingEta) ? $incomingEta->format('Y-m-d') : '—' }}</span>
                        <span class="text-base font-semibold text-primary">{{ number_format((float) $incomingQty, 3) }} units</span>
                    </div>
                    <p class="mt-2 text-xs text-ink-faint">On order, not yet received into stock.</p>
                </x-card>
            @endif

            <x-card title="Settings">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Reorder level</dt>
                        <dd class="font-medium text-ink">{{ $item->reorder_level }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Reorder quantity</dt>
                        <dd class="font-medium text-ink">{{ $item->reorder_quantity }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-faint">Status</dt>
                        <dd><x-inventory.status-badge :status="$item->is_active ? 'active' : 'inactive'" /></dd>
                    </div>
                </dl>
            </x-card>
        </div>

        <div class="lg:col-span-2">
            <x-card title="Movement history" :padding="false">
                @if ($item->movements->isEmpty())
                    <div class="p-6">
                        <x-empty-state icon="inventory" title="No movements yet" description="Stock adjustments, transfers, write-offs and production will appear here." />
                    </div>
                @else
                    <div class="table-wrap !border-0 !rounded-none">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Warehouse</th>
                                    <th class="text-right">Change</th>
                                    <th>Type</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($item->movements->reverse() as $movement)
                                    <tr>
                                        <td class="whitespace-nowrap text-ink-soft">{{ $movement->created_at->format('M j, Y H:i') }}</td>
                                        <td class="text-ink-soft">{{ $movement->warehouse?->name }}</td>
                                        <td class="text-right">
                                            @if ((float) $movement->quantity_change > 0)
                                                <span class="font-medium text-emerald-600">+{{ number_format((float) $movement->quantity_change, 3) }}</span>
                                            @else
                                                <span class="font-medium text-rose-500">{{ number_format((float) $movement->quantity_change, 3) }}</span>
                                            @endif
                                        </td>
                                        <td class="text-ink-soft">{{ \Illuminate\Support\Str::of($movement->movement_type)->replace('_', ' ')->headline() }}</td>
                                        <td class="text-ink-soft">
                                            @if ($movement->reference_type)
                                                {{ class_basename($movement->reference_type) }} #{{ $movement->reference_id }}
                                            @else
                                                —
                                            @endif
                                            @if ($movement->note)
                                                <span class="block text-xs text-ink-faint">{{ $movement->note }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
