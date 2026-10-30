<x-app-layout :pageTitle="'Edit '.$claim->number">
    <x-slot name="header">
        <x-page-header title="Edit expense claim" :description="$claim->number" icon="edit" />
    </x-slot>

    @include('accounting._tabs')

    <div class="mt-6 max-w-2xl">
        <x-card>
            <form method="POST" action="{{ route('accounting.expense_claims.update', $claim) }}" class="space-y-4" x-data="{ discount: '{{ old('discount_mode', ((float) ($claim->discount_amount ?? 0)) > 0 ? 'amount' : 'percent') }}', discountPercent: {{ (float) old('discount_percent', (float) ($claim->discount_percent ?? 0)) }}, discountAmount: {{ (float) old('discount_amount', (float) ($claim->discount_amount ?? 0)) }}, setDiscount(m) { this.discount = m; if (m === 'percent') { this.discountAmount = 0; } else { this.discountPercent = 0; } } }">
                @csrf
                @method('PUT')

                @if ($claim->status === 'approved')
                    <x-alert type="info">
                        This claim has been approved, so its amount already left the paid-from account.
                        Saving reverses that deduction and re-deducts the figures you save now.
                    </x-alert>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="employee_name" label="Employee name" required value="{{ old('employee_name', $claim->employee_name) }}" />
                    <x-input name="expense_date" label="Expense date" type="date" required value="{{ old('expense_date', $claim->expense_date->format('Y-m-d')) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-select name="expense_category_id" label="Expense category" required>
                            <option value="">Choose a category…</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(old('expense_category_id', $claim->expense_category_id) == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-ink-faint">
                            Categories are managed under
                            <a href="{{ route('accounting.expense_categories.index') }}" class="font-medium text-primary-ink hover:underline">Expense categories</a>.
                            The account mapped to the category decides where the ledger posts this claim on approval.
                        </p>
                    </div>
                    <x-input name="merchant" label="Merchant" value="{{ old('merchant', $claim->merchant) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="amount" label="Amount" type="number" step="0.01" min="0" required value="{{ old('amount', $claim->amount) }}" />
                    <x-input name="currency" label="Currency" required value="{{ old('currency', $claim->currency) }}" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="discount_mode">Discount</label>
                        <select id="discount_mode" name="discount_mode" x-on:change="setDiscount($event.target.value)" class="select-input">
                            <option value="percent" @selected(old('discount_mode', ((float) ($claim->discount_amount ?? 0)) > 0 ? 'amount' : 'percent') === 'percent')>Percent</option>
                            <option value="amount" @selected(old('discount_mode', ((float) ($claim->discount_amount ?? 0)) > 0 ? 'amount' : 'percent') === 'amount')>Fixed amount</option>
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
                                <option value="{{ $account->id }}" @selected(old('bank_account_id', $claim->bank_account_id) == $account->id)>
                                    {{ $account->name }}{{ $account->account_type === 'cash' ? ' (cash)' : '' }}
                                </option>
                            @endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-ink-faint">
                            The moment the claim is approved, its amount is deducted from the selected account.
                        </p>
                    </div>
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
