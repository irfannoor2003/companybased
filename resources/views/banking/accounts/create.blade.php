<x-app-layout :pageTitle="'New bank account'">
    <x-slot name="header">
        <x-page-header title="New bank account" description="Add a bank or cash account." icon="banking">
            <x-slot name="actions">
                <x-button href="{{ route('banking.accounts.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Account details">
            <form method="POST" action="{{ route('banking.accounts.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="name" label="Account name" required value="{{ old('name') }}" placeholder="e.g. Main operating account" />
                    <x-input name="account_number" label="Account number" value="{{ old('account_number') }}" placeholder="e.g. 1234-5678" />
                    <x-input name="bank_name" label="Bank name" value="{{ old('bank_name') }}" placeholder="e.g. First National" />
                    <x-input name="branch" label="Branch" value="{{ old('branch') }}" placeholder="e.g. Downtown" />
                    <x-select name="account_type" label="Account type">
                        @foreach (\App\Models\BankAccount::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(old('account_type', 'checking') === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="currency" label="Currency" value="{{ old('currency', 'USD') }}" placeholder="USD, EUR…" />
                </div>

                <x-input name="opening_balance" label="Opening balance" type="number" step="0.01" value="{{ old('opening_balance', 0) }}" hint="Balance held when this account was opened." />

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div>
                    <x-toggle name="is_active" label="Active" :checked="old('is_active', true)" />
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('banking.accounts.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create account</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
