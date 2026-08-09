<x-app-layout :pageTitle="'Edit warehouse — '.$warehouse->name">
    <x-slot name="header">
        <x-page-header title="Edit warehouse" description="{{ $warehouse->code }}" icon="building">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.warehouses.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Warehouse details">
            <form method="POST" action="{{ route('inventory.warehouses.update', $warehouse) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="name" label="Name" required value="{{ old('name', $warehouse->name) }}" />
                    <x-input name="code" label="Code" required value="{{ old('code', $warehouse->code) }}" />
                </div>

                <x-textarea name="address" label="Address" rows="3">{{ old('address', $warehouse->address) }}</x-textarea>

                <div>
                    <x-toggle name="is_active" label="Active" :checked="old('is_active', $warehouse->is_active)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
