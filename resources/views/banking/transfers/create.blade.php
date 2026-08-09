<x-app-layout :pageTitle="'New bank transfer'">
    <x-slot name="header">
        <x-page-header title="New bank transfer" description="Move money between two accounts." icon="arrow-right">
            <x-slot name="actions">
                <x-button href="{{ route('banking.transfers.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-3xl">
        <x-card title="Transfer details">
            <form method="POST" action="{{ route('banking.transfers.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-select name="from_account_id" label="From account" required>
                        <option value="">— Select account —</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('from_account_id') == $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                        @endforeach
                    </x-select>
                    <x-select name="to_account_id" label="To account" required>
                        <option value="">— Select account —</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}" @selected(old('to_account_id') == $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                        @endforeach
                    </x-select>
                    <x-input name="transfer_date" label="Transfer date" type="date" value="{{ old('transfer_date', now()->toDateString()) }}" required />
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required />
                </div>

                <x-textarea name="description" label="Description" rows="3" placeholder="e.g. Operating funds to savings">{{ old('description') }}</x-textarea>

                <div class="flex justify-end gap-3 border-t border-line pt-4">
                    <x-button href="{{ route('banking.transfers.index') }}" variant="ghost">Cancel</x-button>
                    <x-button type="submit" icon="save">Create transfer</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
