<x-app-layout :pageTitle="'New expense claim'">
    <x-slot name="header">
        <x-page-header title="New expense claim" description="Record an employee expense for approval and reimbursement." icon="plus" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.expense_claims.store') }}" class="space-y-4" x-data="{ discount: '{{ old('discount_mode', 'percent') }}', discountPercent: {{ (float) old('discount_percent', 0) }}, discountAmount: {{ (float) old('discount_amount', 0) }}, setDiscount(m) { this.discount = m; if (m === 'percent') { this.discountAmount = 0; } else { this.discountPercent = 0; } } }">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="employee_name" label="Employee name" required placeholder="Who incurred this expense?" value="{{ old('employee_name') }}" />
                    <x-input name="expense_date" label="Expense date" type="date" required value="{{ old('expense_date', now()->toDateString()) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="expense_category_id" label="Expense category" required>
                            <option value="">Choose a category…</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('expense_category_id') == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-ink-faint">
                            Categories are created by the Admin under
                            <a href="{{ route('accounting.expense_categories.index') }}" class="font-medium text-primary-ink hover:underline">Expense categories</a>.
                            The account mapped to the category decides where the ledger posts this claim on approval.
                        </p>
                        @if ($categories->isEmpty())
                            <x-alert type="warning" class="mt-2">
                                No expense categories yet. An Admin must create one before a claim can be recorded.
                            </x-alert>
                        @endif
                    </div>
                    <x-input name="merchant" label="Merchant" placeholder="Where was it spent?" value="{{ old('merchant') }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required value="{{ old('amount') }}" />
                    <x-input name="currency" label="Currency" required value="{{ old('currency', settings('company.currency', 'USD')) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="discount_mode">Discount</label>
                        <select id="discount_mode" name="discount_mode" x-on:change="setDiscount($event.target.value)" class="select-input">
                            <option value="percent" @selected(old('discount_mode', 'percent') === 'percent')>Percent</option>
                            <option value="amount" @selected(old('discount_mode', 'percent') === 'amount')>Fixed amount</option>
                        </select>
                        <p class="mt-1 text-xs text-ink-faint">
                            Optional — the other party gave the employee a discount off the bill before reimbursement.
                        </p>
                        <div x-show="discount === 'percent'" class="mt-2">
                            <input type="number" step="0.01" min="0" max="100" name="discount_percent" x-model="discountPercent" class="input" placeholder="10 = 10% off">
                        </div>
                        <div x-show="discount === 'amount'" class="mt-2">
                            <input type="number" step="0.01" min="0" name="discount_amount" x-model="discountAmount" class="input" placeholder="10 = Rs 10 off">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="bank_account_id" label="Paid from (bank account or cash)">
                            <option value="">Will be paid outside tracked accounts</option>
                            @foreach ($bankAccounts as $account)
                                <option value="{{ $account->id }}" @selected(old('bank_account_id') == $account->id)>
                                    {{ $account->name }}{{ $account->account_type === 'cash' ? ' (cash)' : '' }}
                                </option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-ink-faint">
                            The moment the claim is approved, its amount is deducted from the selected account.
                        </p>
                    </div>
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
