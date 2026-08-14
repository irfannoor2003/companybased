<x-app-layout :pageTitle="'Edit supplier'">
    <x-slot name="header">
        <x-page-header title="Edit supplier" description="{{ $supplier->company_name }}" icon="suppliers">
            <x-slot name="actions">
                <x-button href="{{ route('suppliers.suppliers.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-5xl">
        <x-card title="Supplier details">
            <form method="POST" action="{{ route('suppliers.suppliers.update', $supplier) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="company_name" label="Company name" required value="{{ old('company_name', $supplier->company_name) }}" class="sm:col-span-2" />
                    <x-input name="short_code" label="Short code" value="{{ old('short_code', $supplier->short_code) }}" placeholder="e.g. ACME, VEND-001" hint="Unique identifier used in document numbers or references." />
                    <x-input name="contact_name" label="Contact person" value="{{ old('contact_name', $supplier->contact_name) }}" />
                    <x-input name="email" label="Email" type="email" value="{{ old('email', $supplier->email) }}" />
                    <x-input name="phone" label="Phone" value="{{ old('phone', $supplier->phone) }}" />
                    <x-input name="mobile" label="Mobile" value="{{ old('mobile', $supplier->mobile) }}" />
                    <x-input name="tax_number" label="Tax number" value="{{ old('tax_number', $supplier->tax_number) }}" />
                    <x-input name="payment_terms" label="Payment terms" value="{{ old('payment_terms', $supplier->payment_terms) }}" placeholder="e.g. net 30" />
                    <x-select name="currency" label="Currency">
                        <option value="">— Default —</option>
                        @foreach (currency_options() as $code => $label)
                            <option value="{{ $code }}" @selected(old('currency', $supplier->currency) === $code)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="address" label="Street address" value="{{ old('address', $supplier->address) }}" class="sm:col-span-2" />
                    <x-input name="city" label="City" value="{{ old('city', $supplier->city) }}" />
                    <x-input name="country" label="Country" value="{{ old('country', $supplier->country) }}" />
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $supplier->notes) }}</x-textarea>

                <div class="border-t border-line pt-4">
                    <x-toggle name="is_active" label="Active" description="Inactive suppliers cannot be selected on new documents." :checked="old('is_active', $supplier->is_active)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('suppliers.suppliers.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Save changes</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
