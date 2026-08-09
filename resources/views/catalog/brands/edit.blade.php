<x-app-layout :pageTitle="'Edit brand'">
    <x-slot name="header">
        <x-page-header title="Edit brand" description="{{ $brand->name }}" icon="tag">
            <x-slot name="actions">
                <x-button href="{{ route('catalog.brands.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Brand details">
            <form method="POST" action="{{ route('catalog.brands.update', $brand) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <x-input name="name" label="Brand name" required value="{{ old('name', $brand->name) }}" />
                <x-textarea name="description" label="Description" rows="3">{{ old('description', $brand->description) }}</x-textarea>

                <div class="border-t border-line pt-4">
                    <x-toggle name="is_active" label="Active" description="Inactive brands are hidden from new product forms." :checked="old('is_active', $brand->is_active)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('catalog.brands.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
