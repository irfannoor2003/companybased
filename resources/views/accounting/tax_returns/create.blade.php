<x-app-layout :pageTitle="'New tax return'">
    <x-slot name="header">
        <x-page-header title="New tax return" description="Record a period tax return and the amount due." icon="plus" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.tax_returns.store') }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="tax_type" label="Tax type" required>
                            @foreach (\App\Models\TaxReturn::typeOptions() as $type)
                                <option value="{{ $type }}" @selected(old('tax_type') === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-input name="period_label" label="Period label" required placeholder="e.g. Q3 2026" value="{{ old('period_label') }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="period_start" label="Period start" type="date" required value="{{ old('period_start') }}" />
                    <x-input name="period_end" label="Period end" type="date" required value="{{ old('period_end') }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="gross_receipts" label="Gross receipts" type="number" step="0.01" min="0" value="{{ old('gross_receipts', '0.00') }}" />
                    <x-input name="taxable_amount" label="Taxable amount" type="number" step="0.01" min="0" value="{{ old('taxable_amount', '0.00') }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-input name="tax_collected" label="Tax collected" type="number" step="0.01" min="0" value="{{ old('tax_collected', '0.00') }}" />
                    <x-input name="tax_credits" label="Tax credits" type="number" step="0.01" min="0" value="{{ old('tax_credits', '0.00') }}" />
                    <x-input name="tax_due" label="Tax due" type="number" step="0.01" min="0" required value="{{ old('tax_due') }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="currency" label="Currency" required value="{{ old('currency', settings('company.currency', 'USD')) }}" />
                </div>

                <x-textarea name="notes" label="Notes" value="{{ old('notes') }}" />

                <div class="flex items-center gap-2 pt-2">
                    <x-button type="submit" icon="save">Create return</x-button>
                    <x-button href="{{ route('accounting.tax_returns.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>