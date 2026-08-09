<x-app-layout :pageTitle="'Edit customer'">
    <x-slot name="header">
        <x-page-header title="Edit customer" description="{{ $customer->company_name }}" icon="users">
            <x-slot name="actions">
                <x-button href="{{ route('sales.customers.show', $customer) }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-5xl">
        <x-card title="Customer details">
            <form method="POST" action="{{ route('sales.customers.update', $customer) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="company_name" label="Company name" required value="{{ old('company_name', $customer->company_name) }}" class="sm:col-span-2" />
                    <x-input name="contact_name" label="Contact person" value="{{ old('contact_name', $customer->contact_name) }}" />
                    <x-input name="email" label="Email" type="email" value="{{ old('email', $customer->email) }}" />
                    <x-input name="phone" label="Phone" value="{{ old('phone', $customer->phone) }}" />
                    <x-input name="mobile" label="Mobile" value="{{ old('mobile', $customer->mobile) }}" />
                    <x-input name="tax_number" label="Tax number" value="{{ old('tax_number', $customer->tax_number) }}" />
                    <x-select name="price_list_id" label="Price list">
                        <option value="">— Default —</option>
                        @foreach ($priceLists as $priceList)
                            <option value="{{ $priceList->id }}" @selected(old('price_list_id', $customer->price_list_id) == $priceList->id)>{{ $priceList->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="credit_limit" label="Credit limit" type="number" step="0.01" min="0" value="{{ old('credit_limit', $customer->credit_limit) }}" hint="0 = no credit." />
                    <x-input name="currency" label="Currency" value="{{ old('currency', $customer->currency) }}" placeholder="USD, EUR…" />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="address" label="Street address" value="{{ old('address', $customer->address) }}" class="sm:col-span-2" />
                    <x-input name="city" label="City" value="{{ old('city', $customer->city) }}" />
                    <x-input name="country" label="Country" value="{{ old('country', $customer->country) }}" />
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $customer->notes) }}</x-textarea>

                <div class="border-t border-line pt-4">
                    <x-toggle name="is_active" label="Active" description="Inactive customers cannot be selected on new documents." :checked="old('is_active', $customer->is_active)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('sales.customers.show', $customer) }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
