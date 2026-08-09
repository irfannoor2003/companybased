<x-app-layout :pageTitle="'New reconciliation'">
    <x-slot name="header">
        <x-page-header title="New reconciliation" description="Match a bank statement against the books." icon="check-circle">
            <x-slot name="actions">
                <x-button href="{{ route('banking.reconciliations.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Statement details">
            <form method="POST" action="{{ route('banking.reconciliations.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-select name="bank_account_id" label="Account" required>
                        <option value="">— Select account —</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('bank_account_id') == $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                        @endforeach
                    </x-select>
                    <x-input name="statement_date" label="Statement date" type="date" value="{{ old('statement_date', now()->toDateString()) }}" required />
                    <x-input name="statement_ending_balance" label="Statement ending balance" type="number" step="0.01" value="{{ old('statement_ending_balance') }}" required hint="The closing balance shown on the bank statement." />
                </div>

                <x-textarea name="notes" label="Notes" rows="3">{{ old('notes') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('banking.reconciliations.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Start reconciliation</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
