<x-app-layout :pageTitle="'Record drawing'">
    <x-slot name="header">
        <x-page-header title="Record drawing" description="Log an owner withdrawal or capital distribution." icon="arrow-right" />
    </x-slot>

    @include('capital._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('capital.drawings.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="drawing_date" label="Date" type="date" required value="{{ old('drawing_date', now()->format('Y-m-d')) }}" :error="$errors->first('drawing_date')" />
                    <x-input name="recipient" label="Recipient" required placeholder="e.g. Kwame Mensah" value="{{ old('recipient') }}" :error="$errors->first('recipient')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('amount') }}" :error="$errors->first('amount')" />
                    <x-input name="currency" label="Currency" placeholder="GHS, USD, EUR" value="{{ old('currency', 'GHS') }}" :error="$errors->first('currency')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="method" label="Method" :error="$errors->first('method')">
                        <option value="">Select method</option>
                        @foreach (\App\Models\CapitalDrawing::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('method') === $method)>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Record drawing</x-button>
                    <x-button href="{{ route('capital.drawings.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>