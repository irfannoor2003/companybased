<x-app-layout :pageTitle="'Edit BOM — '.$billOfMaterial->name">
    <x-slot name="header">
        <x-page-header title="Edit bill of materials" description="{{ $billOfMaterial->item?->product?->name }}" icon="document">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.bill_of_materials.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="BOM details">
            <form method="POST" action="{{ route('inventory.bill_of_materials.update', $billOfMaterial) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-input name="name" label="Name" required value="{{ old('name', $billOfMaterial->name) }}" />
                    <x-select name="item_id" label="Finished item" required>
                        @foreach ($items as $entry)
                            <option value="{{ $entry->id }}" @selected(old('item_id', $billOfMaterial->item_id) == $entry->id)>{{ $entry->product?->name }}{{ $entry->product?->sku ? ' ('.$entry->product->sku.')' : '' }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\InventoryBillOfMaterial::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', $billOfMaterial->status) === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-input name="version" label="Version" value="{{ old('version', $billOfMaterial->version) }}" />

                @php
                    $initialItems = $billOfMaterial->items->map(fn ($item) => [
                        'item_id' => (string) ($item->component_item_id ?? ''),
                        'quantity' => (float) $item->quantity,
                        'wastage_percent' => (float) $item->wastage_percent,
                    ])->all();
                @endphp

                <x-inventory.bom-items-editor :items="$items" :initial-items="$initialItems" />

                <x-textarea name="note" label="Note" rows="3">{{ old('note', $billOfMaterial->note) }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
