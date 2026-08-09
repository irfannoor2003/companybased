<x-app-layout :pageTitle="'Edit transaction'">
    <x-slot name="header">
        <x-page-header title="Edit transaction" description="Update the buy or sell transaction." icon="money" />
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('investments.transactions.update', $transaction) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="investment_id" label="Investment" :error="$errors->first('investment_id')">
                        <option value="">Select investment</option>
                        @foreach ($investments as $investment)
                            <option value="{{ $investment->id }}" @selected(old('investment_id', $transaction->investment_id) == $investment->id)>{{ $investment->code }} — {{ $investment->name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="type" label="Type" :error="$errors->first('type')">
                        <option value="buy" @selected(old('type', $transaction->type) === 'buy')>Buy</option>
                        <option value="sell" @selected(old('type', $transaction->type) === 'sell')>Sell</option>
                    </x-select>
                </div>

                <x-input name="transaction_date" label="Transaction date" type="date" required value="{{ old('transaction_date', $transaction->transaction_date?->format('Y-m-d')) }}" :error="$errors->first('transaction_date')" />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="quantity" label="Quantity" type="number" step="any" min="0" required value="{{ old('quantity', $transaction->quantity) }}" :error="$errors->first('quantity')" />
                    <x-input name="unit_price" label="Unit price" type="number" step="0.01" min="0" required value="{{ old('unit_price', $transaction->unit_price) }}" :error="$errors->first('unit_price')" />
                    <x-input name="fees" label="Fees" type="number" step="0.01" min="0" value="{{ old('fees', $transaction->fees) }}" :error="$errors->first('fees')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes', $transaction->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Update transaction</x-button>
                    <x-button href="{{ route('investments.transactions.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>