<x-app-layout :pageTitle="'Edit price list'">
    <x-slot name="header">
        <x-page-header title="Edit price list" description="{{ $priceList->name }}" icon="money">
            <x-slot name="actions">
                <x-button href="{{ route('catalog.price_lists.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $initialItems = $priceList->items->map(fn ($item) => ['product_id' => (string) $item->product_id, 'price' => $item->price])->values()->all();
    @endphp

    <div class="mt-6 max-w-5xl">
        <x-card title="Price list details">
            <form method="POST" action="{{ route('catalog.price_lists.update', $priceList) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="name" label="Name" required value="{{ old('name', $priceList->name) }}" />
                    <x-select name="type" label="Type" required>
                        <option value="retail" @selected(old('type', $priceList->type) === 'retail')>Retail</option>
                        <option value="wholesale" @selected(old('type', $priceList->type) === 'wholesale')>Wholesale</option>
                        <option value="custom" @selected(old('type', $priceList->type) === 'custom')>Custom</option>
                    </x-select>
                    <x-input name="currency" label="Currency" value="{{ old('currency', $priceList->currency) }}" hint="ISO code, e.g. USD. Leave empty to inherit the company currency." />
                    <x-input name="markup_percent" label="Markup %" type="number" step="0.01" min="0" value="{{ old('markup_percent', $priceList->markup_percent) }}" hint="Optional auto-markup applied to cost price." />
                </div>

                <x-textarea name="description" label="Description" rows="2">{{ old('description', $priceList->description) }}</x-textarea>

                <div class="flex flex-wrap gap-6 border-t border-line pt-4">
                    <x-toggle name="is_default" label="Default list" description="Used when no specific list applies to a customer." :checked="old('is_default', $priceList->is_default)" />
                    <x-toggle name="is_active" label="Active" description="Inactive lists cannot be assigned." :checked="old('is_active', $priceList->is_active)" />
                </div>

                <div class="border-t border-line pt-4">
                    @include('catalog.price_lists.partials.items-editor', [
                        'products' => $products,
                        'initialItems' => $initialItems,
                    ])
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('catalog.price_lists.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
