<x-app-layout :pageTitle="'Price list '.$priceList->name">
    <x-slot name="header">
        <x-page-header title="{{ $priceList->name }}" description="Price list details and pricing." icon="money">
            <x-slot name="actions">
                @if (auth()->user()->can('catalog.price_lists.edit'))
                    <x-button :href="route('catalog.price_lists.edit', $priceList)" variant="secondary" icon="edit">Edit</x-button>
                @endif
                <x-button href="{{ route('catalog.price_lists.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $currency = $priceList->currency ?: (settings('base_currency') ?: settings('company.currency') ?: 'USD');
    @endphp

    <div class="mt-6 max-w-5xl space-y-6">
        <x-card title="Details">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-ink-faint">Name</dt>
                    <dd class="mt-1 flex items-center gap-2 font-medium text-ink">
                        {{ $priceList->name }}
                        @if ($priceList->is_default)
                            <x-badge color="primary">Default</x-badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">Type</dt>
                    <dd class="mt-1">
                        <x-badge color="neutral">{{ ucfirst($priceList->type) }}</x-badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">Status</dt>
                    <dd class="mt-1">
                        @if ($priceList->is_active)
                            <x-badge color="success" dot>Active</x-badge>
                        @else
                            <x-badge color="danger" dot>Inactive</x-badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">Currency</dt>
                    <dd class="mt-1 text-sm text-ink-soft">{{ $priceList->currency ?: 'Company default ('.$currency.')' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">Markup</dt>
                    <dd class="mt-1 text-sm text-ink-soft">{{ $priceList->markup_percent > 0 ? $priceList->markup_percent.'%' : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">Items</dt>
                    <dd class="mt-1 text-sm text-ink-soft">{{ $priceList->items_count ?? $priceList->items->count() }} product{{ ($priceList->items_count ?? $priceList->items->count()) === 1 ? '' : 's' }}</dd>
                </div>
                @if ($priceList->description)
                    <div class="col-span-2 sm:col-span-3">
                        <dt class="text-xs text-ink-faint">Description</dt>
                        <dd class="mt-1 text-sm text-ink-soft">{{ $priceList->description }}</dd>
                    </div>
                @endif
            </dl>
        </x-card>

        <x-card :padding="false" :title="'Pricing ('.$priceList->items->count().' products)'">
            @if ($priceList->items->isEmpty())
                <x-empty-state icon="money" title="No products yet" description="Add products and prices to this price list." />
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-right">List price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($priceList->items as $item)
                                <tr>
                                    <td>
                                        <p class="font-medium text-ink">{{ $item->product?->name ?: '—' }}</p>
                                        @if ($item->product?->category)
                                            <p class="text-xs text-ink-faint">{{ $item->product->category->name }}</p>
                                        @endif
                                    </td>
                                    <td class="text-ink-soft">{{ $item->product?->sku ?: '—' }}</td>
                                    <td class="text-right font-medium text-ink">{{ money($item->price, $currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
