<x-app-layout :pageTitle="'Edit dividend'">
    <x-slot name="header">
        <x-page-header title="Edit dividend" description="Update dividend or interest income received." icon="document" />
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('investments.dividends.update', $dividend) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="investment_id" label="Investment" :error="$errors->first('investment_id')">
                        <option value="">Select investment</option>
                        @foreach ($investments as $investment)
                            <option value="{{ $investment->id }}" @selected(old('investment_id', $dividend->investment_id) == $investment->id)>{{ $investment->code }} — {{ $investment->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="dividend_date" label="Date" type="date" required value="{{ old('dividend_date', $dividend->dividend_date?->format('Y-m-d')) }}" :error="$errors->first('dividend_date')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" required value="{{ old('amount', $dividend->amount) }}" :error="$errors->first('amount')" />
                    <x-input name="currency" label="Currency" value="{{ old('currency', $dividend->currency) }}" :error="$errors->first('currency')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes', $dividend->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Update dividend</x-button>
                    <x-button href="{{ route('investments.dividends.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>