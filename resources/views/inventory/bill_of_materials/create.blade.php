<x-app-layout :pageTitle="'New bill of materials'">
    <x-slot name="header">
        <x-page-header title="New bill of materials" description="Define the recipe for a finished item." icon="document">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.bill_of_materials.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="BOM details">
            <form method="POST" action="{{ route('inventory.bill_of_materials.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-input name="name" label="Name" required value="{{ old('name') }}" placeholder="e.g. Standard widget" />
                    <x-select name="item_id" label="Finished item" required>
                        <option value="">— Select item —</option>
                        @foreach ($items as $entry)
                            <option value="{{ $entry->id }}" @selected(old('item_id') == $entry->id)>{{ $entry->product?->name }}{{ $entry->product?->sku ? ' ('.$entry->product->sku.')' : '' }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\InventoryBillOfMaterial::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-input name="version" label="Version" value="{{ old('version', '1') }}" />

                <x-inventory.bom-items-editor :items="$items" />

                <x-textarea name="note" label="Note" rows="3">{{ old('note') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('inventory.bill_of_materials.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create BOM</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
