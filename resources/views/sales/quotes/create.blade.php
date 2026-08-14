<x-app-layout :pageTitle="'New quote'">
    <x-slot name="header">
        <x-page-header title="New quote" description="Build a quotation for a customer." icon="document">
            <x-slot name="actions">
                <x-button href="{{ route('sales.quotes.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-6xl">
        <x-card title="Quote details">
            <form method="POST" action="{{ route('sales.quotes.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" x-data="currencyFromEntity()" x-init="initCurrencySelect()">
                    <x-select name="customer_id" label="Customer" required @change="$event.target.value && syncCurrency($event.target)">
                        <option value="">— Select customer —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" data-currency="{{ $customer->currency ?? '' }}" @selected(old('customer_id') == $customer->id)>{{ $customer->company_name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="price_list_id" label="Price list">
                        <option value="">— Default —</option>
                        @foreach ($priceLists as $priceList)
                            <option value="{{ $priceList->id }}" @selected(old('price_list_id') == $priceList->id)>{{ $priceList->name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="status" label="Status">
                        @foreach (\App\Models\SalesQuote::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'draft') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="issue_date" label="Issue date" type="date" value="{{ old('issue_date', now()->toDateString()) }}" />
                    <x-input name="valid_until" label="Valid until" type="date" value="{{ old('valid_until') }}" />
                    <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-sales.line-items-editor :products="$products" :initial-items="[]" :currency="old('currency', settings('company.currency', 'USD'))" />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.quotes.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create quote</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
