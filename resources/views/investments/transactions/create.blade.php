<x-app-layout :pageTitle="'Record transaction'">
    <x-slot name="header">
        <x-page-header title="Record transaction" description="Log a buy or sell against a portfolio holding." icon="money" />
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('investments.transactions.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="investment_id" label="Investment" :error="$errors->first('investment_id')">
                        <option value="">Select investment</option>
                        @foreach ($investments as $investment)
                            <option value="{{ $investment->id }}" @selected(old('investment_id') == $investment->id)>{{ $investment->code }} — {{ $investment->name }}</option>
                        @endforeach
                    </x-select>
                    <x-select name="type" label="Type" :error="$errors->first('type')">
                        <option value="buy" @selected(old('type', 'buy') === 'buy')>Buy</option>
                        <option value="sell" @selected(old('type') === 'sell')>Sell</option>
                    </x-select>
                </div>

                <x-input name="transaction_date" label="Transaction date" type="date" required value="{{ old('transaction_date', now()->format('Y-m-d')) }}" :error="$errors->first('transaction_date')" />

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="quantity" label="Quantity" type="number" step="any" min="0" required placeholder="e.g. 1000" value="{{ old('quantity') }}" :error="$errors->first('quantity')" />
                    <x-input name="unit_price" label="Unit price" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('unit_price') }}" :error="$errors->first('unit_price')" />
                    <x-input name="fees" label="Fees" type="number" step="0.01" min="0" placeholder="0.00" value="{{ old('fees', 0) }}" :error="$errors->first('fees')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Record transaction</x-button>
                    <x-button href="{{ route('investments.transactions.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>