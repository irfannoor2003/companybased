<x-app-layout :pageTitle="'New expense claim'">
    <x-slot name="header">
        <x-page-header title="New expense claim" description="Record an employee expense for approval and reimbursement." icon="plus" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.expense_claims.store') }}" class="space-y-4">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="employee_name" label="Employee name" required placeholder="Who incurred this expense?" value="{{ old('employee_name') }}" />
                    <x-input name="expense_date" label="Expense date" type="date" required value="{{ old('expense_date', now()->toDateString()) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="expense_type" label="Expense type" required>
                            @foreach (\App\Models\ExpenseClaim::typeOptions() as $type)
                                <option value="{{ $type }}" @selected(old('expense_type') === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <x-input name="merchant" label="Merchant" placeholder="Where was it spent?" value="{{ old('merchant') }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required value="{{ old('amount') }}" />
                    <x-input name="currency" label="Currency" required value="{{ old('currency', settings('company.currency', 'USD')) }}" />
                </div>

                <x-textarea name="notes" label="Notes" value="{{ old('notes') }}" />

                <div class="flex items-center gap-2 pt-2">
                    <x-button type="submit" icon="save">Create claim</x-button>
                    <x-button href="{{ route('accounting.expense_claims.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>