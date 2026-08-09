<x-app-layout :pageTitle="'New bank transaction'">
    <x-slot name="header">
        <x-page-header title="New bank transaction" description="Record a deposit or withdrawal." icon="money">
            <x-slot name="actions">
                <x-button href="{{ route('banking.transactions.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Transaction details">
            <form method="POST" action="{{ route('banking.transactions.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-select name="bank_account_id" label="Account" required>
                        <option value="">— Select account —</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('bank_account_id', $presetAccount) == $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                        @endforeach
                    </x-select>
                    <x-select name="type" label="Type" required>
                        @foreach (\App\Models\BankTransaction::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="transaction_date" label="Date" type="date" value="{{ old('transaction_date', now()->toDateString()) }}" required />
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-input name="counterparty" label="Counterparty" value="{{ old('counterparty') }}" placeholder="e.g. Customer, supplier, bank…" />
                    <x-input name="reference" label="Reference" value="{{ old('reference') }}" placeholder="e.g. bank ref, cheque no." />
                </div>

                <x-input name="description" label="Description" value="{{ old('description') }}" placeholder="Short note about this transaction…" />

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('banking.transactions.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Record transaction</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
