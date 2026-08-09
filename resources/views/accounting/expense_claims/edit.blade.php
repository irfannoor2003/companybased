<x-app-layout :pageTitle="'Edit '.$claim->number">
    <x-slot name="header">
        <x-page-header title="Edit expense claim" :description="$claim->number" icon="edit" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.expense_claims.update', $claim) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="employee_name" label="Employee name" required value="{{ old('employee_name', $claim->employee_name) }}" />
                    <x-input name="expense_date" label="Expense date" type="date" required value="{{ old('expense_date', $claim->expense_date->format('Y-m-d')) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="expense_type" label="Expense type" required>
                            @foreach (\App\Models\ExpenseClaim::typeOptions() as $type)
                                <option value="{{ $type }}" @selected(old('expense_type', $claim->expense_type) === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-input name="merchant" label="Merchant" value="{{ old('merchant', $claim->merchant) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required value="{{ old('amount', $claim->amount) }}" />
                    <x-input name="currency" label="Currency" required value="{{ old('currency', $claim->currency) }}" />
                </div>

                <x-textarea name="notes" label="Notes" value="{{ old('notes', $claim->notes) }}" />

                <div class="flex items-center gap-2 pt-2">
                    <x-button type="submit" icon="save">Save changes</x-button>
                    <x-button href="{{ route('accounting.expense_claims.show', $claim) }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>