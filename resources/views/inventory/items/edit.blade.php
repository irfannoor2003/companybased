<x-app-layout :pageTitle="'Edit item — '.$item->product?->name">
    <x-slot name="header">
        <x-page-header title="Edit item" description="{{ $item->product?->name }}" icon="package">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.items.show', $item) }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Item settings">
            <form method="POST" action="{{ route('inventory.items.update', $item) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="reorder_level" label="Reorder level" type="number" step="0.001" min="0" value="{{ old('reorder_level', $item->reorder_level) }}" />
                    <x-input name="reorder_quantity" label="Reorder quantity" type="number" step="0.001" min="0" value="{{ old('reorder_quantity', $item->reorder_quantity) }}" />
                </div>

                <div>
                    <x-toggle name="is_active" label="Active" description="Inactive items are hidden from stock forms." :checked="old('is_active', $item->is_active)" />
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $item->notes) }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
