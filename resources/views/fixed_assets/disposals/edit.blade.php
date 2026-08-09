<x-app-layout :pageTitle="'Edit disposal'">
    <x-slot name="header">
        <x-page-header title="Edit disposal" description="Update the disposal details." icon="arrow-right" />
    </x-slot>

    @include('fixed_assets._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('fixed_assets.disposals.update', $disposal) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="disposal_date" label="Disposal date" type="date" required value="{{ old('disposal_date', $disposal->disposal_date?->format('Y-m-d')) }}" :error="$errors->first('disposal_date')" />
                    <x-input name="method" label="Method" placeholder="sold, scrapped, donated, other" value="{{ old('method', $disposal->method) }}" :error="$errors->first('method')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="proceeds" label="Proceeds" type="number" step="0.01" min="0" required value="{{ old('proceeds', $disposal->proceeds) }}" :error="$errors->first('proceeds')" />
                    <div class="rounded-lg border border-line bg-surface-muted/50 px-4 py-3 text-sm">
                        <span class="text-ink-faint">Book value</span>
                        <span class="block font-medium text-ink">{{ money($disposal->book_value) }}</span>
                    </div>
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes', $disposal->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Update disposal</x-button>
                    <x-button href="{{ route('fixed_assets.disposals.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>