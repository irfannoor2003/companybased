<x-app-layout :pageTitle="'New product'">
    <x-slot name="header">
        <x-page-header title="New product" description="Add a product to your catalogue." icon="package">
            <x-slot name="actions">
                <x-button href="{{ route('catalog.products.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-5xl">
        <x-card title="Product details">
            <form method="POST" action="{{ route('catalog.products.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="name" label="Product name" required value="{{ old('name') }}" placeholder="e.g. Cordless Drill 20V" class="sm:col-span-2" />
                    <x-input name="sku" label="SKU" value="{{ old('sku') }}" placeholder="e.g. DRL-20V-BLK" />
                    <x-input name="barcode" label="Barcode" value="{{ old('barcode') }}" placeholder="e.g. 6291041500213" />
                    <x-select name="brand_id" label="Brand">
                        <option value="">— None —</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}" @selected(old('brand_id') == $brand->id)>{{ $brand->name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="category_id" label="Category">
                        <option value="">— None —</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->path() }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="unit" label="Unit" value="{{ old('unit', 'pcs') }}" placeholder="pcs, box, kg…" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="cost_price" label="Cost price" type="number" step="0.01" min="0" value="{{ old('cost_price', 0) }}" />
                    <x-input name="retail_price" label="Retail price" type="number" step="0.01" min="0" value="{{ old('retail_price', 0) }}" />
                    <x-input name="wholesale_price" label="Wholesale price" type="number" step="0.01" min="0" value="{{ old('wholesale_price') }}" />
                    <x-input name="min_price" label="Minimum sale price" type="number" step="0.01" min="0" value="{{ old('min_price') }}" hint="Lowest price a sales order may be discounted to." />
                </div>

                <x-textarea name="description" label="Description" rows="3">{{ old('description') }}</x-textarea>

                <div class="border-t border-line pt-4">
                    <x-toggle name="is_active" label="Active" description="Inactive products are hidden from new orders and price lists." :checked="old('is_active', true)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('catalog.products.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create product</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
