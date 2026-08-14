<x-app-layout :pageTitle="'New supplier'">
    <x-slot name="header">
        <x-page-header title="New supplier" description="Add a supplier to your purchase book." icon="suppliers">
            <x-slot name="actions">
                <x-button href="{{ route('suppliers.suppliers.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-5xl">
        <x-card title="Supplier details">
            <form method="POST" action="{{ route('suppliers.suppliers.store') }}" class="space-y-5" x-data="shortCodeSuggest('{{ route('suppliers.suppliers.short-code-suggest') }}')" @submit="markTouched()">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="company_name" label="Company name" required value="{{ old('company_name') }}" placeholder="e.g. Acme Traders LLC" class="sm:col-span-2" x-on:input.debounce.400ms="suggestShortCode()" />
                    <x-input name="short_code" label="Short code" value="{{ old('short_code') }}" placeholder="e.g. ACME, VEND-001" hint="Unique identifier used in document numbers or references." x-on:input="markTouched()" />
                    <x-input name="contact_name" label="Contact person" value="{{ old('contact_name') }}" placeholder="e.g. Jane Doe" />
                    <x-input name="email" label="Email" type="email" value="{{ old('email') }}" placeholder="supplier@example.com" />
                    <x-input name="phone" label="Phone" value="{{ old('phone') }}" />
                    <x-input name="mobile" label="Mobile" value="{{ old('mobile') }}" />
                    <x-input name="tax_number" label="Tax number" value="{{ old('tax_number') }}" />
                    <x-input name="payment_terms" label="Payment terms" value="{{ old('payment_terms') }}" placeholder="e.g. net 30" />
                    <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', settings('company.currency', 'USD')) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="address" label="Street address" value="{{ old('address') }}" class="sm:col-span-2" />
                    <x-input name="city" label="City" value="{{ old('city') }}" />
                    <x-input name="country" label="Country" value="{{ old('country') }}" />
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="border-t border-line pt-4">
                    <x-toggle name="is_active" label="Active" description="Inactive suppliers cannot be selected on new documents." :checked="old('is_active', true)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('suppliers.suppliers.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create supplier</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
