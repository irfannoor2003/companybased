<x-app-layout :pageTitle="'Edit contribution'">
    <x-slot name="header">
        <x-page-header title="Edit contribution" description="Update the capital contribution details." icon="money" />
    </x-slot>

    @include('capital._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('capital.contributions.update', $contribution) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="contribution_date" label="Date" type="date" required value="{{ old('contribution_date', $contribution->contribution_date?->format('Y-m-d')) }}" :error="$errors->first('contribution_date')" />
                    <x-input name="contributor" label="Contributor" required placeholder="e.g. John Doe" value="{{ old('contributor', $contribution->contributor) }}" :error="$errors->first('contributor')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('amount', $contribution->amount) }}" :error="$errors->first('amount')" />
                    <x-input name="currency" label="Currency" placeholder="{{ settings('company.currency', 'USD') }}" value="{{ old('currency', $contribution->currency) }}" :error="$errors->first('currency')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="method" label="Method" :error="$errors->first('method')">
                        <option value="">Select method</option>
                        @foreach (\App\Models\CapitalContribution::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('method', $contribution->method) === $method)>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes', $contribution->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Update contribution</x-button>
                    <x-button href="{{ route('capital.contributions.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>