<x-app-layout :pageTitle="'Add asset'">
    <x-slot name="header">
        <x-page-header title="Add asset" description="Register a new fixed asset with its depreciation settings." icon="assets" />
    </x-slot>

    @include('fixed_assets._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('fixed_assets.assets.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="name" label="Asset name" required placeholder="e.g. Toyota Hilux 2021" value="{{ old('name') }}" :error="$errors->first('name')" />
                    <x-input name="category" label="Category" placeholder="e.g. Vehicles, Computers" value="{{ old('category') }}" :error="$errors->first('category')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="purchase_date" label="Purchase date" type="date" value="{{ old('purchase_date', now()->format('Y-m-d')) }}" :error="$errors->first('purchase_date')" />
                    <x-input name="serial_number" label="Serial number" value="{{ old('serial_number') }}" :error="$errors->first('serial_number')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="purchase_cost" label="Purchase cost" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('purchase_cost') }}" :error="$errors->first('purchase_cost')" />
                    <x-input name="salvage_value" label="Salvage value" type="number" step="0.01" min="0" placeholder="0.00" value="{{ old('salvage_value', 0) }}" :error="$errors->first('salvage_value')" />
                    <x-input name="useful_life_months" label="Useful life (months)" type="number" min="0" required placeholder="e.g. 36" value="{{ old('useful_life_months') }}" :error="$errors->first('useful_life_months')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-select name="depreciation_method" label="Depreciation method" :error="$errors->first('depreciation_method')">
                        @foreach (\App\Models\FixedAsset::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('depreciation_method', 'straight_line') === $method)>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="depreciation_rate" label="Reducing-balance rate %" type="number" step="0.0001" min="0" max="100" placeholder="e.g. 2.5" value="{{ old('depreciation_rate') }}" :error="$errors->first('depreciation_rate')" />
                    <x-select name="status" label="Status" :error="$errors->first('status')">
                        @foreach (\App\Models\FixedAsset::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', 'in_use') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="location" label="Location" placeholder="e.g. Accra office" value="{{ old('location') }}" :error="$errors->first('location')" />
                    <x-input name="department" label="Department" placeholder="e.g. Operations" value="{{ old('department') }}" :error="$errors->first('department')" />
                </div>

                <x-input name="supplier" label="Supplier" placeholder="Where it was purchased from" value="{{ old('supplier') }}" :error="$errors->first('supplier')" />

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Create asset</x-button>
                    <x-button href="{{ route('fixed_assets.assets.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>