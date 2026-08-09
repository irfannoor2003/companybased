<x-app-layout :pageTitle="'Add inventory item'">
    <x-slot name="header">
        <x-page-header title="Add inventory item" description="Track a product in inventory with reorder settings." icon="inventory">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.items.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Item details">
            <form method="POST" action="{{ route('inventory.items.store') }}" class="space-y-5">
                @csrf

                <x-select name="product_id" label="Product" required>
                    <option value="">— Select product —</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>
                            {{ $product->name }}{{ $product->sku ? ' ('.$product->sku.')' : '' }}
                        </option>
                    @endforeach
                </x-select>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="reorder_level" label="Reorder level" type="number" step="0.001" min="0" value="{{ old('reorder_level', 0) }}" hint="When on-hand drops to this, the item is flagged." />
                    <x-input name="reorder_quantity" label="Reorder quantity" type="number" step="0.001" min="0" value="{{ old('reorder_quantity', 0) }}" />
                </div>

                <div>
                    <x-toggle name="is_active" label="Active" description="Inactive items are hidden from stock forms." :checked="old('is_active', true)" />
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('inventory.items.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Add item</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
