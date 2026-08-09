<x-app-layout :pageTitle="'Edit asset'">
    <x-slot name="header">
        <x-page-header title="Edit asset" description="Update the asset and its depreciation settings." icon="assets" />
    </x-slot>

    @include('fixed_assets._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('fixed_assets.assets.update', $asset) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="rounded-lg border border-line bg-surface-muted/50 px-4 py-3">
                    <span class="font-mono text-sm font-medium text-ink">{{ $asset->asset_code }}</span>
                    <span class="ml-2 text-sm text-ink-faint">Book value {{ money($asset->bookValue()) }}</span>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="name" label="Asset name" required value="{{ old('name', $asset->name) }}" :error="$errors->first('name')" />
                    <x-input name="category" label="Category" value="{{ old('category', $asset->category) }}" :error="$errors->first('category')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="purchase_date" label="Purchase date" type="date" value="{{ old('purchase_date', $asset->purchase_date?->format('Y-m-d')) }}" :error="$errors->first('purchase_date')" />
                    <x-input name="serial_number" label="Serial number" value="{{ old('serial_number', $asset->serial_number) }}" :error="$errors->first('serial_number')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="purchase_cost" label="Purchase cost" type="number" step="0.01" min="0" required value="{{ old('purchase_cost', $asset->purchase_cost) }}" :error="$errors->first('purchase_cost')" />
                    <x-input name="salvage_value" label="Salvage value" type="number" step="0.01" min="0" value="{{ old('salvage_value', $asset->salvage_value) }}" :error="$errors->first('salvage_value')" />
                    <x-input name="useful_life_months" label="Useful life (months)" type="number" min="0" required value="{{ old('useful_life_months', $asset->useful_life_months) }}" :error="$errors->first('useful_life_months')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-select name="depreciation_method" label="Depreciation method" :error="$errors->first('depreciation_method')">
                        @foreach (\App\Models\FixedAsset::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('depreciation_method', $asset->depreciation_method) === $method)>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="depreciation_rate" label="Reducing-balance rate %" type="number" step="0.0001" min="0" max="100" value="{{ old('depreciation_rate', $asset->depreciation_rate) }}" :error="$errors->first('depreciation_rate')" />
                    <x-select name="status" label="Status" :error="$errors->first('status')">
                        @foreach (\App\Models\FixedAsset::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', $asset->status) === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="location" label="Location" value="{{ old('location', $asset->location) }}" :error="$errors->first('location')" />
                    <x-input name="department" label="Department" value="{{ old('department', $asset->department) }}" :error="$errors->first('department')" />
                </div>

                <x-input name="supplier" label="Supplier" value="{{ old('supplier', $asset->supplier) }}" :error="$errors->first('supplier')" />

                <x-textarea name="notes" label="Notes">{{ old('notes', $asset->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Update asset</x-button>
                    <x-button href="{{ route('fixed_assets.assets.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>