<x-app-layout :pageTitle="'Edit drawing'">
    <x-slot name="header">
        <x-page-header title="Edit drawing" description="Update the capital drawing details." icon="arrow-right" />
    </x-slot>

    @include('capital._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('capital.drawings.update', $drawing) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="drawing_date" label="Date" type="date" required value="{{ old('drawing_date', $drawing->drawing_date?->format('Y-m-d')) }}" :error="$errors->first('drawing_date')" />
                    <x-input name="recipient" label="Recipient" required placeholder="e.g. John Doe" value="{{ old('recipient', $drawing->recipient) }}" :error="$errors->first('recipient')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('amount', $drawing->amount) }}" :error="$errors->first('amount')" />
                    <x-input name="currency" label="Currency" placeholder="{{ settings('company.currency', 'USD') }}" value="{{ old('currency', $drawing->currency) }}" :error="$errors->first('currency')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="method" label="Method" :error="$errors->first('method')">
                        <option value="">Select method</option>
                        @foreach (\App\Models\CapitalDrawing::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('method', $drawing->method) === $method)>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes', $drawing->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Update drawing</x-button>
                    <x-button href="{{ route('capital.drawings.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>