<x-app-layout :pageTitle="'Edit category'">
    <x-slot name="header">
        <x-page-header title="Edit category" description="{{ $category->name }}" icon="document">
            <x-slot name="actions">
                <x-button href="{{ route('catalog.categories.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Category details">
            <form method="POST" action="{{ route('catalog.categories.update', $category) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="name" label="Category name" required value="{{ old('name', $category->name) }}" />
                    <x-select name="parent_id" label="Parent category" hint="Leave empty for a top-level category.">
                        <option value="">— None —</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}" @selected((int) old('parent_id', $category->parent_id) === $parent->id)>{{ $parent->path() }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-textarea name="description" label="Description" rows="3">{{ old('description', $category->description) }}</x-textarea>

                <div class="border-t border-line pt-4">
                    <x-toggle name="is_active" label="Active" description="Inactive categories are hidden from new product forms." :checked="old('is_active', $category->is_active)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('catalog.categories.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
