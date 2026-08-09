<x-app-layout :pageTitle="'Edit '.$transaction->number">
    <x-slot name="header">
        <x-page-header title="Edit {{ $transaction->number }}" description="{{ $transaction->account?->name }}" icon="money">
            <x-slot name="actions">
                <x-button href="{{ route('banking.transactions.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Transaction details">
                <form method="POST" action="{{ route('banking.transactions.update', $transaction) }}" class="space-y-5">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-select name="bank_account_id" label="Account" required>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected(old('bank_account_id', $transaction->bank_account_id) == $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                            @endforeach
                        </x-select>
                        <x-select name="type" label="Type" required>
                            @foreach (\App\Models\BankTransaction::typeOptions() as $type)
                                <option value="{{ $type }}" @selected(old('type', $transaction->type) === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </x-select>
                        <x-input name="transaction_date" label="Date" type="date" value="{{ old('transaction_date', $transaction->transaction_date?->format('Y-m-d')) }}" required />
                        <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" value="{{ old('amount', $transaction->amount) }}" required />
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-input name="counterparty" label="Counterparty" value="{{ old('counterparty', $transaction->counterparty) }}" />
                        <x-input name="reference" label="Reference" value="{{ old('reference', $transaction->reference) }}" />
                    </div>

                    <x-input name="description" label="Description" value="{{ old('description', $transaction->description) }}" />

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button type="submit" icon="save">Save changes</x-button>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Summary">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Type</dt><dd class="text-ink">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Amount</dt><dd class="font-medium text-ink">{{ $transaction->isDebit() ? '−' : '+' }}{{ money($transaction->amount, $transaction->account?->currency) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Reconciled</dt><dd class="text-ink">{{ $transaction->is_reconciled ? 'Yes' : 'No' }}</dd></div>
                    @if ($transaction->reference_type)
                        <div class="flex justify-between"><dt class="text-ink-faint">Source</dt><dd class="text-ink">Transfer {{ $transaction->reference }}</dd></div>
                    @endif
                </dl>
            </x-card>
        </div>
    </div>
</x-app-layout>
