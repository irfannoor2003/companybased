<x-app-layout :pageTitle="'Record contribution'">
    <x-slot name="header">
        <x-page-header title="Record contribution" description="Log a capital injection from an owner or investor." icon="money" />
    </x-slot>

    @include('capital._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('capital.contributions.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="contribution_date" label="Date" type="date" required value="{{ old('contribution_date', now()->format('Y-m-d')) }}" :error="$errors->first('contribution_date')" />
                    <x-input name="contributor" label="Contributor" required placeholder="e.g. John Doe" value="{{ old('contributor') }}" :error="$errors->first('contributor')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('amount') }}" :error="$errors->first('amount')" />
                    <x-input name="currency" label="Currency" placeholder="{{ settings('company.currency', 'USD') }}" value="{{ old('currency', settings('company.currency', 'USD')) }}" :error="$errors->first('currency')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="method" label="Method" :error="$errors->first('method')">
                        <option value="">Select method</option>
                        @foreach (\App\Models\CapitalContribution::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('method') === $method)>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Record contribution</x-button>
                    <x-button href="{{ route('capital.contributions.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
