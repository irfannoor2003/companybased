<x-app-layout :pageTitle="'Adjust stock — '.$item->product?->name">
    <x-slot name="header">
        <x-page-header title="Adjust stock" description="{{ $item->product?->name }} ({{ $item->product?->sku ?: 'No SKU' }})" icon="zap">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.items.show', $item) }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Stock adjustment">
                <form method="POST" action="{{ route('inventory.items.adjust.store', $item) }}" class="space-y-5">
                    @csrf

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-select name="warehouse_id" label="Warehouse" required>
                            <option value="">— Select warehouse —</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="quantity" label="Quantity change" type="number" step="0.001" required
                            value="{{ old('quantity') }}" hint="Use a positive number to add stock, negative to remove." />
                    </div>

                    <x-input name="reason" label="Reason" value="{{ old('reason') }}" placeholder="e.g. Cycle count correction, damaged stock…" />

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button href="{{ route('inventory.items.show', $item) }}" variant="ghost">Cancel</x-button>
                        <x-button type="submit" icon="save">Apply adjustment</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <x-card title="Current stock">
            @forelse ($item->stock as $stock)
                <div class="flex items-center justify-between border-b border-line py-2 last:border-0">
                    <span class="text-sm text-ink-soft">{{ $stock->warehouse?->name }}</span>
                    <span class="text-sm font-medium text-ink">{{ number_format((float) $stock->quantity, 3) }}</span>
                </div>
            @empty
                <p class="text-sm text-ink-faint">No stock recorded yet.</p>
            @endforelse
        </x-card>
    </div>
</x-app-layout>
