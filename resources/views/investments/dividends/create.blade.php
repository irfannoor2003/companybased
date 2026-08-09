<x-app-layout :pageTitle="'Record dividend'">
    <x-slot name="header">
        <x-page-header title="Record dividend" description="Log dividend or interest income from a holding." icon="document" />
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('investments.dividends.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="investment_id" label="Investment" :error="$errors->first('investment_id')">
                        <option value="">Select investment</option>
                        @foreach ($investments as $investment)
                            <option value="{{ $investment->id }}" @selected(old('investment_id') == $investment->id)>{{ $investment->code }} — {{ $investment->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="dividend_date" label="Date" type="date" required value="{{ old('dividend_date', now()->format('Y-m-d')) }}" :error="$errors->first('dividend_date')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" required placeholder="0.00" value="{{ old('amount') }}" :error="$errors->first('amount')" />
                    <x-input name="currency" label="Currency" placeholder="GHS, USD" value="{{ old('currency', 'GHS') }}" :error="$errors->first('currency')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Record dividend</x-button>
                    <x-button href="{{ route('investments.dividends.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>