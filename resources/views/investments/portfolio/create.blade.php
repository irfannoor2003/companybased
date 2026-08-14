<x-app-layout :pageTitle="'Add investment'">
    <x-slot name="header">
        <x-page-header title="Add investment" description="Record a new holding in the company portfolio." icon="investments" />
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('investments.portfolio.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="name" label="Investment name" required placeholder="e.g. Company shares" value="{{ old('name') }}" :error="$errors->first('name')" />
                    <x-select name="type" label="Type" :error="$errors->first('type')">
                        <option value="">Select type</option>
                        @foreach (\App\Models\Investment::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="institution" label="Institution / broker" placeholder="e.g. Local bank, Broker" value="{{ old('institution') }}" :error="$errors->first('institution')" />
                    <x-select name="status" label="Status" :error="$errors->first('status')">
                        @foreach (\App\Models\Investment::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="purchase_date" label="Purchase date" type="date" value="{{ old('purchase_date', now()->format('Y-m-d')) }}" :error="$errors->first('purchase_date')" />
                    <x-input name="maturity_date" label="Maturity date" type="date" value="{{ old('maturity_date') }}" :error="$errors->first('maturity_date')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="quantity" label="Quantity / units" type="number" step="any" min="0" required placeholder="e.g. 5000" value="{{ old('quantity') }}" :error="$errors->first('quantity')" />
                    <x-input name="unit_cost" label="Unit cost" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('unit_cost') }}" :error="$errors->first('unit_cost')" />
                    <x-input name="total_cost" label="Total invested" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('total_cost') }}" :error="$errors->first('total_cost')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="current_price" label="Current price" type="number" step="0.01" min="0" placeholder="0.00" value="{{ old('current_price') }}" :error="$errors->first('current_price')" />
                    <x-input name="current_value" label="Current value" type="number" step="0.01" min="0" placeholder="0.00" value="{{ old('current_value') }}" :error="$errors->first('current_value')" />
                    <x-input name="currency" label="Currency" placeholder="{{ settings('company.currency', 'USD') }}" value="{{ old('currency', settings('company.currency', 'USD')) }}" :error="$errors->first('currency')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Add investment</x-button>
                    <x-button href="{{ route('investments.portfolio.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>