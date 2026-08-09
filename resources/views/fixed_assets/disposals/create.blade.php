<x-app-layout :pageTitle="'Record disposal'">
    <x-slot name="header">
        <x-page-header title="Record disposal" description="Retire a fixed asset by sale, scrapping or donation." icon="arrow-right" />
    </x-slot>

    @include('fixed_assets._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('fixed_assets.disposals.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="fixed_asset_id" label="Asset" :error="$errors->first('fixed_asset_id')">
                        <option value="">Select asset</option>
                        @foreach ($assets as $asset)
                            <option value="{{ $asset->id }}" @selected(old('fixed_asset_id') == $asset->id)>
                                {{ $asset->asset_code }} — {{ $asset->name }} ({{ money($asset->bookValue()) }})
                            </option>
                        @endforeach
                    </x-select>
                    <x-input name="disposal_date" label="Disposal date" type="date" required value="{{ old('disposal_date', now()->format('Y-m-d')) }}" :error="$errors->first('disposal_date')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-select name="method" label="Method" :error="$errors->first('method')">
                        <option value="">Select method</option>
                        @foreach (\App\Models\FixedAssetDisposal::methodOptions() as $method)
                            <option value="{{ $method }}" @selected(old('method') === $method)>{{ ucfirst($method) }}</option>
                        @endforeach
                    </x-select>
                    <x-input name="proceeds" label="Proceeds" type="number" step="0.01" min="0" required placeholder="0.00" value="{{ old('proceeds', 0) }}" :error="$errors->first('proceeds')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Record disposal</x-button>
                    <x-button href="{{ route('fixed_assets.disposals.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>