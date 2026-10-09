<div class="border-t border-line pt-5">
    <h3 class="text-sm font-semibold text-ink">Opening balance</h3>
    <p class="mt-1 text-xs text-ink-soft">
        What this customer already owed you — or had already paid you — before their first document existed in this system.
        Leave it at 0 for a genuinely new customer. This amount is added to their first statement and is included in every balance from then on.
    </p>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-input
            name="opening_balance"
            label="Amount"
            type="number"
            step="0.01"
            min="0"
            inputmode="decimal"
            value="{{ old('opening_balance', $customer?->opening_balance != 0 ? abs((float) $customer->opening_balance) : 0) }}"
        />

        @php
            $direction = old('opening_balance_direction', $customer?->opening_balance < 0 ? 'credit' : 'debit');
        @endphp
        <x-select name="opening_balance_direction" label="Direction">
            <option value="debit" @selected($direction === 'debit')>+ Owed to me</option>
            <option value="credit" @selected($direction === 'credit')>− In credit with customer</option>
        </x-select>

        <x-input
            name="opening_balance_date"
            label="As at"
            type="date"
            value="{{ old('opening_balance_date', $customer?->opening_balance_date?->toDateString()) }}"
        />
    </div>

    <div class="mt-3 rounded-lg border border-line bg-surface-muted/40 p-3 text-xs text-ink-soft">
        <p class="font-medium text-ink">How to read the two directions</p>
        <ul class="mt-1.5 space-y-1 pl-4">
            <li class="list-disc">
                <span class="font-medium text-ink">+ Owed to me</span> — the customer owed you this much before you started
                invoicing them. It increases what they owe, and every balance shown for this customer is this much higher.
            </li>
            <li class="list-disc">
                <span class="font-medium text-ink">− In credit with customer</span> — you had already received this much from them,
                such as an advance or a deposit. It reduces what they owe, and can make their balance negative.
            </li>
        </ul>
    </div>
</div>
