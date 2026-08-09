<x-app-layout :pageTitle="'Edit '.$tax->number">
    <x-slot name="header">
        <x-page-header title="Edit tax return" :description="$tax->number" icon="edit">
            <x-slot name="actions">
                <x-button href="{{ route('accounting.tax_returns.show', $tax) }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.tax_returns.update', $tax) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="tax_type" label="Tax type" required>
                            @foreach (\App\Models\TaxReturn::typeOptions() as $type)
                                <option value="{{ $type }}" @selected(old('tax_type', $tax->tax_type) === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-input name="period_label" label="Period label" required value="{{ old('period_label', $tax->period_label) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="period_start" label="Period start" type="date" required value="{{ old('period_start', $tax->period_start->format('Y-m-d')) }}" />
                    <x-input name="period_end" label="Period end" type="date" required value="{{ old('period_end', $tax->period_end->format('Y-m-d')) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="gross_receipts" label="Gross receipts" type="number" step="0.01" min="0" value="{{ old('gross_receipts', $tax->gross_receipts) }}" />
                    <x-input name="taxable_amount" label="Taxable amount" type="number" step="0.01" min="0" value="{{ old('taxable_amount', $tax->taxable_amount) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="tax_collected" label="Tax collected" type="number" step="0.01" min="0" value="{{ old('tax_collected', $tax->tax_collected) }}" />
                    <x-input name="tax_credits" label="Tax credits" type="number" step="0.01" min="0" value="{{ old('tax_credits', $tax->tax_credits) }}" />
                    <x-input name="tax_due" label="Tax due" type="number" step="0.01" min="0" required value="{{ old('tax_due', $tax->tax_due) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="currency" label="Currency" required value="{{ old('currency', $tax->currency) }}" />
                </div>

                <x-textarea name="notes" label="Notes" value="{{ old('notes', $tax->notes) }}" />

                <div class="flex items-center gap-2 pt-2">
                    <x-button type="submit" icon="save">Save changes</x-button>
                    <x-button href="{{ route('accounting.tax_returns.show', $tax) }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>