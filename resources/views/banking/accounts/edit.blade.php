<x-app-layout :pageTitle="'Edit '.$account->name">
    <x-slot name="header">
        <x-page-header title="Edit {{ $account->name }}" description="{{ $account->bank_name ?: 'Bank account' }}" icon="banking">
            <x-slot name="actions">
                <x-button href="{{ route('banking.accounts.show', $account) }}" variant="secondary" icon="eye">View</x-button>
                <x-button href="{{ route('banking.accounts.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Account details">
                <form method="POST" action="{{ route('banking.accounts.update', $account) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-input name="name" label="Account name" required value="{{ old('name', $account->name) }}" />
                        <x-input name="account_number" label="Account number" value="{{ old('account_number', $account->account_number) }}" />
                        <x-input name="bank_name" label="Bank name" value="{{ old('bank_name', $account->bank_name) }}" />
                        <x-input name="branch" label="Branch" value="{{ old('branch', $account->branch) }}" />
                        <x-select name="account_type" label="Account type">
                            @foreach (\App\Models\BankAccount::typeOptions() as $type)
                                <option value="{{ $type }}" @selected(old('account_type', $account->account_type) === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="currency" label="Currency" value="{{ old('currency', $account->currency) }}" />
                    </div>

                    <x-input name="opening_balance" label="Opening balance" type="number" step="0.01" value="{{ old('opening_balance', $account->opening_balance) }}" hint="Only affects the starting balance; leave as-is once transactions exist." />

                    <x-textarea name="notes" label="Notes" rows="3">{{ old('notes', $account->notes) }}</x-textarea>

                    <div>
                        <x-toggle name="is_active" label="Active" :checked="old('is_active', $account->is_active)" />
                    </div>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button type="submit" icon="save">Save changes</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Summary">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Type</dt><dd class="text-ink">{{ ucfirst($account->account_type) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Currency</dt><dd class="text-ink">{{ $account->currency }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Opening balance</dt><dd class="text-ink">{{ money($account->opening_balance, $account->currency) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-2"><dt class="text-ink font-semibold">Current balance</dt><dd class="font-semibold text-ink">{{ money($account->balance(), $account->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Reconciled balance</dt><dd class="text-ink">{{ money($account->clearedBalance(), $account->currency) }}</dd></div>
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
