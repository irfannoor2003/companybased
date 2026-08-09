<x-app-layout :pageTitle="'Edit investment'">
    <x-slot name="header">
        <x-page-header title="Edit investment" description="Update the holding and its current valuation." icon="investments" />
    </x-slot>

    @include('investments._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('investments.portfolio.update', $investment) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="rounded-lg border border-line bg-surface-muted/50 px-4 py-3">
                    <span class="font-mono text-sm font-medium text-ink">{{ $investment->code }}</span>
                    <span class="ml-2 text-sm text-ink-faint">Gain/loss {{ money($investment->gainLoss(), $investment->currency) }}</span>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="name" label="Investment name" required value="{{ old('name', $investment->name) }}" :error="$errors->first('name')" />
                    <x-select name="type" label="Type" :error="$errors->first('type')">
                        @foreach (\App\Models\Investment::typeOptions() as $type)
                            <option value="{{ $type }}" @selected(old('type', $investment->type) === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="institution" label="Institution / broker" value="{{ old('institution', $investment->institution) }}" :error="$errors->first('institution')" />
                    <x-select name="status" label="Status" :error="$errors->first('status')">
                        @foreach (\App\Models\Investment::statusOptions() as $status)
                            <option value="{{ $status }}" @selected(old('status', $investment->status) === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="purchase_date" label="Purchase date" type="date" value="{{ old('purchase_date', $investment->purchase_date?->format('Y-m-d')) }}" :error="$errors->first('purchase_date')" />
                    <x-input name="maturity_date" label="Maturity date" type="date" value="{{ old('maturity_date', $investment->maturity_date?->format('Y-m-d')) }}" :error="$errors->first('maturity_date')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="quantity" label="Quantity / units" type="number" step="any" min="0" required value="{{ old('quantity', $investment->quantity) }}" :error="$errors->first('quantity')" />
                    <x-input name="unit_cost" label="Unit cost" type="number" step="0.01" min="0" required value="{{ old('unit_cost', $investment->unit_cost) }}" :error="$errors->first('unit_cost')" />
                    <x-input name="total_cost" label="Total invested" type="number" step="0.01" min="0" required value="{{ old('total_cost', $investment->total_cost) }}" :error="$errors->first('total_cost')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="current_price" label="Current price" type="number" step="0.01" min="0" value="{{ old('current_price', $investment->current_price) }}" :error="$errors->first('current_price')" />
                    <x-input name="current_value" label="Current value" type="number" step="0.01" min="0" value="{{ old('current_value', $investment->current_value) }}" :error="$errors->first('current_value')" />
                    <x-input name="currency" label="Currency" value="{{ old('currency', $investment->currency) }}" :error="$errors->first('currency')" />
                </div>

                <x-textarea name="notes" label="Notes">{{ old('notes', $investment->notes) }}</x-textarea>

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Update investment</x-button>
                    <x-button href="{{ route('investments.portfolio.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>