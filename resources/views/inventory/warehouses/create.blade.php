<x-app-layout :pageTitle="'New warehouse'">
    <x-slot name="header">
        <x-page-header title="New warehouse" description="Add a stock location." icon="building">
            <x-slot name="actions">
                <x-button href="{{ route('inventory.warehouses.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Warehouse details">
            <form method="POST" action="{{ route('inventory.warehouses.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="name" label="Name" required value="{{ old('name') }}" placeholder="e.g. Main warehouse" />
                    <x-input name="code" label="Code" required value="{{ old('code') }}" placeholder="e.g. MAIN" hint="Short unique code." />
                </div>

                <x-textarea name="address" label="Address" rows="3">{{ old('address') }}</x-textarea>

                <div>
                    <x-toggle name="is_active" label="Active" :checked="old('is_active', true)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('inventory.warehouses.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create warehouse</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
